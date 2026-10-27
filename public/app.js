const reviewDialog = document.getElementById('review-dialog');
if (reviewDialog) {
    const closeLink = reviewDialog.querySelector('[data-close-review]');
    const documentId = new URL(window.location.href).searchParams.get('document');
    reviewDialog.showModal();
    document.body.classList.add('review-dialog-open');
    function closeReview() {
        reviewDialog.close();
        document.body.classList.remove('review-dialog-open');
        window.history.replaceState(null, '', closeLink.href);
        const trigger = [...document.querySelectorAll('a[href]')].find(link => !reviewDialog.contains(link) && new URL(link.href).searchParams.get('document') === documentId);
        trigger?.focus();
    }
    closeLink.addEventListener('click', event => {
        event.preventDefault();
        closeReview();
    });
    reviewDialog.addEventListener('cancel', event => {
        event.preventDefault();
        closeReview();
    });
}

document.getElementById('notification-toggle')?.addEventListener('click',()=>{const p=document.getElementById('notifications');p.hidden=!p.hidden;});
document.getElementById('select-all')?.addEventListener('change',e=>document.querySelectorAll('.row-select').forEach(c=>c.checked=e.target.checked));
const input=document.getElementById('form-files'),drop=document.getElementById('dropzone');
let imageUrls=[];
function showFiles(){imageUrls.forEach(URL.revokeObjectURL);imageUrls=[];const previews=document.getElementById('file-previews');previews.replaceChildren();let invalid=false;Array.from(input.files).forEach((file,index)=>{const row=document.createElement('div');row.className='preview-item';const image=document.createElement('img');if(['image/jpeg','image/png','image/webp'].includes(file.type)){const url=URL.createObjectURL(file);imageUrls.push(url);image.src=url;}const text=document.createElement('div'),name=document.createElement('span'),info=document.createElement('small');name.textContent=file.name;info.textContent=(file.size/1048576).toFixed(2)+' MB · Ready to upload';if(file.size>15*1048576||!['image/jpeg','image/png','image/webp'].includes(file.type)){info.textContent='Unsupported image or file exceeds 15 MB';info.className='preview-error';invalid=true;}text.append(name,info);const remove=document.createElement('button');remove.type='button';remove.textContent='×';remove.title='Remove file';remove.onclick=()=>{const transfer=new DataTransfer();Array.from(input.files).filter((_,i)=>i!==index).forEach(f=>transfer.items.add(f));input.files=transfer.files;showFiles();};row.append(image,text,remove);previews.append(row);});document.getElementById('file-count').textContent=input.files.length+' file'+(input.files.length===1?'':'s')+' selected';const submit=document.getElementById('upload-submit');if(submit)submit.disabled=invalid||input.files.length===0||input.files.length>20;}
input?.addEventListener('change',showFiles);
if(drop){['dragenter','dragover'].forEach(type=>drop.addEventListener(type,e=>{e.preventDefault();drop.classList.add('dragover');}));['dragleave','drop'].forEach(type=>drop.addEventListener(type,e=>{e.preventDefault();drop.classList.remove('dragover');}));drop.addEventListener('drop',e=>{input.files=e.dataTransfer.files;showFiles();});}
document.getElementById('upload-form')?.addEventListener('submit',()=>{const submit=document.getElementById('upload-submit');if(submit){submit.disabled=true;submit.textContent='Uploading securely…';}});
let zoom=1,rotation=0;const docImage=document.getElementById('document-image');
function updateImage(){if(docImage)(document.querySelector('.image-canvas')||docImage).style.transform=`scale(${zoom}) rotate(${rotation}deg)`;}
window.changeZoom=delta=>{zoom=Math.max(.5,Math.min(4,zoom+delta));updateImage();};window.rotateImage=()=>{rotation+=90;updateImage();};
document.querySelectorAll('[data-box]').forEach(el=>el.addEventListener('focus',()=>{const box=JSON.parse(el.dataset.box);if(box.length!==4||!docImage)return;const overlay=document.getElementById('field-overlay');if(overlay){overlay.hidden=false;Object.assign(overlay.style,{top:box[0]/10+'%',left:box[1]/10+'%',width:(box[3]-box[1])/10+'%',height:(box[2]-box[0])/10+'%'});}zoom=1.8;(document.querySelector('.image-canvas')||docImage).style.transformOrigin=`${(box[1]+box[3])/20}% ${(box[0]+box[2])/20}%`;updateImage();}));
const stage=document.querySelector('.image-stage');let dragging=false,startX=0,startY=0,scrollX=0,scrollY=0;
document.querySelectorAll('select[data-reviewer]').forEach(select=>{if(select.dataset.reviewer)select.value=select.dataset.reviewer;});
stage?.addEventListener('pointerdown',e=>{dragging=true;startX=e.clientX;startY=e.clientY;scrollX=stage.scrollLeft;scrollY=stage.scrollTop;stage.setPointerCapture(e.pointerId);});stage?.addEventListener('pointermove',e=>{if(dragging){stage.scrollLeft=scrollX-(e.clientX-startX);stage.scrollTop=scrollY-(e.clientY-startY);}});stage?.addEventListener('pointerup',()=>dragging=false);


const reviewForm = document.getElementById('review-form');
if (reviewForm) {
    const fields = [...reviewForm.querySelectorAll('[data-review-field]')];
    const summary = reviewForm.querySelector('[data-review-summary]');
    const nextIssue = reviewForm.querySelector('[data-next-issue]');
    function updateSummary() {
        const count = fields.filter(field => field.classList.contains('has-issue')).length;
        summary.textContent = count ? `${count} field${count === 1 ? '' : 's'} to check before approving` : 'All fields checked. Ready to approve.';
        nextIssue.hidden = count === 0;
    }
    function formatIssue(key, raw) {
        if (!raw) return '';
        if (['contact_number', 'whatsapp_number'].includes(key) && !/^(0\d{9}|\+94\d{9})$/.test(raw.replace(/[\s()\-]/g, ''))) return 'Enter 10 digits starting with 0, or +94 followed by 9 digits.';
        if (key === 'nic_number' && !/^(\d{12}|\d{9}[vVxX])$/.test(raw)) return 'Enter 12 digits, or 9 digits followed by V or X.';
        if (key === 'al_year' && !/^(19|20)\d{2}$/.test(raw)) return 'Enter a four-digit A/L year.';
        if (key === 'email_address' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(raw)) return 'Enter a complete email address, such as name@example.com.';
        if (key === 'inquiry_date') {
            const match = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(raw);
            if (!match) return 'Enter the date as DD/MM/YYYY, for example 24/10/2026.';
            const [, day, month, year] = match.map(Number);
            const date = new Date(0);
            date.setUTCFullYear(year, month - 1, day);
            if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return 'Enter a valid calendar date as DD/MM/YYYY.';
        }
        return '';
    }
    function formatInquiryDate(raw) {
        const text = raw.trim().replace(/\s*([/.\-])\s*/g, '$1');
        let match = /^(\d{4})[/.\-](\d{1,2})[/.\-](\d{1,2})$/.exec(text);
        let year, month, day;
        if (match) {
            [, year, month, day] = match;
        } else {
            match = /^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$/.exec(text);
            if (!match) return raw;
            [, day, month, year] = match;
        }
        const formatted = `${day.padStart(2, '0')}/${month.padStart(2, '0')}/${year}`;
        return formatIssue('inquiry_date', formatted) ? raw : formatted;
    }
    fields.forEach(field => {
        const value = field.querySelector('[data-review-value]');
        const decision = field.querySelector('[data-review-decision]');
        if (field.dataset.key === 'inquiry_date') {
            value.value = formatInquiryDate(value.value);
            value.placeholder = 'DD/MM/YYYY';
        }
        if (!decision) return;
        const issue = field.querySelector('[data-field-issue]');
        const indicator = field.querySelector('[data-field-status]');
        function updateField() {
            const formatError = decision.value === 'blank' ? '' : formatIssue(field.dataset.key, value.value);
            const unresolved = decision.value === 'unreadable';
            const message = formatError || (unresolved ? 'Compare with the original, then choose Matches original or Blank on original.' : '');
            issue.textContent = message;
            issue.hidden = !message;
            field.classList.toggle('has-issue', !!message);
            value.setAttribute('aria-invalid', formatError ? 'true' : 'false');
            indicator.textContent = message ? 'Check this field' : (decision.value === 'blank' ? 'Blank' : 'Confirmed');
            updateSummary();
        }
        value.addEventListener('input', () => {
            decision.value = 'corrected';
            updateField();
        });
        if (field.dataset.key === 'inquiry_date') {
            const formatDate = () => {
                value.value = formatInquiryDate(value.value);
                updateField();
            };
            value.addEventListener('blur', formatDate);
            value.addEventListener('change', formatDate);
        }
        decision.addEventListener('change', updateField);
    });
    let previousIssue = -1;
    nextIssue.addEventListener('click', () => {
        const issues = fields.filter(field => field.classList.contains('has-issue'));
        if (!issues.length) return;
        previousIssue = (previousIssue + 1) % issues.length;
        issues[previousIssue].querySelector('[data-review-value]').focus({preventScroll: true});
        issues[previousIssue].scrollIntoView({behavior: 'smooth', block: 'center'});
    });
    updateSummary();
    if (reviewForm.dataset.hasErrors === 'true') {
        nextIssue.click();
    }
}
