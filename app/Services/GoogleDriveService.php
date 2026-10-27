<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GoogleDriveService
{
    /** @return array{id: string, kind: string, resource_key: ?string} */
    public function parseLink(string $link): array
    {
        $url = parse_url($link);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || strtolower($url['host'] ?? '') !== 'drive.google.com' || isset($url['user']) || isset($url['port'])) {
            throw ValidationException::withMessages(['drive_link' => 'Enter an HTTPS Google Drive file or folder link.']);
        }
        parse_str($url['query'] ?? '', $query);
        $path = $url['path'] ?? '';
        $kind = 'file';
        if (preg_match('~^/drive/(?:u/\d+/)?folders/([a-zA-Z0-9_-]+)/?$~', $path, $matches)) {
            $id = $matches[1];
            $kind = 'folder';
        } elseif (preg_match('~^/file/d/([a-zA-Z0-9_-]+)(?:/view|/preview)?/?$~', $path, $matches)) {
            $id = $matches[1];
        } elseif (in_array($path, ['/open', '/uc'], true) && is_string($query['id'] ?? null) && preg_match('/^[a-zA-Z0-9_-]+$/', $query['id'])) {
            $id = $query['id'];
        } else {
            throw ValidationException::withMessages(['drive_link' => 'Use a Google Drive file or folder sharing link.']);
        }
        $resourceKey = $query['resourcekey'] ?? null;
        if ($resourceKey !== null && (! is_string($resourceKey) || ! preg_match('/^[a-zA-Z0-9_-]+$/', $resourceKey))) {
            throw ValidationException::withMessages(['drive_link' => 'The Drive resource key is invalid.']);
        }

        return ['id' => $id, 'kind' => $kind, 'resource_key' => $resourceKey];
    }

    public function request(string $id, ?string $resourceKey): PendingRequest
    {
        return Http::baseUrl('https://www.googleapis.com/drive/v3')
            ->connectTimeout(15)->timeout(120)
            ->withOptions(['allow_redirects' => false])
            ->withHeaders(['X-Goog-Api-Key' => config('drive.key')])
            ->withHeaders($resourceKey ? ['X-Goog-Drive-Resource-Keys' => $id.'/'.$resourceKey] : []);
    }

    /** @return array{files?: array<int, array{id: string, name: string, mimeType: string, resourceKey?: string}>, nextPageToken?: string} */
    public function folder(string $id, ?string $resourceKey, ?string $pageToken): array
    {
        return $this->request($id, $resourceKey)->get('/files', array_filter([
            'q' => "'".$id."' in parents and trashed = false and (mimeType = 'image/jpeg' or mimeType = 'image/png' or mimeType = 'image/webp')",
            'fields' => 'nextPageToken,files(id,name,mimeType,resourceKey)',
            'pageSize' => 100,
            'pageToken' => $pageToken,
        ], fn ($value) => $value !== null))->throw()->json();
    }

    /** @return array{id: string, name: string, mimeType: string, resourceKey?: string, size?: string} */
    public function file(string $id, ?string $resourceKey): array
    {
        return $this->request($id, $resourceKey)->get('/files/'.$id, [
            'fields' => 'id,name,mimeType,size,resourceKey',
        ])->throw()->json();
    }
}
