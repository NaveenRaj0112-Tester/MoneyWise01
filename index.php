<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
$cssv = @filemtime(__DIR__ . '/assets/app.css');
$logov = @filemtime(__DIR__ . '/Mlogo/MoneywiseLOGO.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>MoneyWise — Expense Tracker</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="assets/app.css?v=<?php echo $cssv; ?>"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<?php require __DIR__ . '/includes/pwa-head.php'; ?>
<meta name="description" content="Smart expense tracker with AI assistant, reports, and budget management">
</head>
<body>
<div id="root"></div>

<script>
const pad2 = n => { n = String(n); return n.length<2 ? '0'+n : n; };
const entries = o => Object.keys(o).map(k=>[k,o[k]]);
const fmt = n => Number.isFinite(+n) ? '₹' + Math.abs(+n).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}) : '₹0.00';
// Local-date-safe "today" (never uses toISOString/UTC so the date cannot shift by a day).
const toDay = () => { const d=new Date(); return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate()); };
const greet = () => { const h=new Date().getHours(); return h<12?'Good Morning':h<17?'Good Afternoon':'Good Evening'; };
const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const MONTH_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
// Format "YYYY-MM-DD" as "07 Aug 2026" using plain string math (no Date parsing = no timezone shift).
const fmtDate = d => {
  if(!d) return '';
  const s=String(d).slice(0,10);
  const p=s.split('-');
  if(p.length!==3) return d;
  const y=+p[0], m=+p[1], dd=+p[2];
  if(!y||!m||!dd||m<1||m>12||dd<1||dd>31) return d;
  return pad2(dd)+' '+MONTH_SHORT[m-1]+' '+y;
};
const fmtDateTime = iso => {
  try{
    const [dPart,tPart]=String(iso).split(/[T ]/);
    let out=fmtDate(dPart);
    if(tPart){
      const [h,mi]=tPart.split(':');
      if(h!=null&&mi!=null){
        let hh=parseInt(h,10)%24, ap=hh>=12?'PM':'AM';
        hh=hh%12||12;
        out+=' '+hh+':'+mi+' '+ap;
      }
    }
    return out;
  }catch(e){ return iso; }
};

const ECATS = {
  Groceries:'🛒',
  Food:'🍔', 'Food & Dining':'🍔',
  Coffee:'☕',
  Car:'🚗', 'Car / Fuel':'🚗',
  Transportation:'🚕',
  Travel:'✈️',
  Home:'🏠', 'Home / Rent':'🏠',
  Electricity:'💡',
  Water:'💧',
  'Mobile / Internet':'📱',
  Health:'💊', 'Health / Medical':'💊',
  Entertainment:'🎬',
  Shopping:'🛍️',
  Education:'📚',
  'EMI / Loan':'💳',
  Bills:'🧾',
  Maintenance:'🛠️',
  Family:'👨‍👩‍👧',
  Pets:'🐶',
  Gifts:'🎁',
  Insurance:'💰',
  Fitness:'🏋️',
  Other:'📦'
};
const ICATS = {
  Salary:'💼',
  Freelance:'💻',
  Business:'📈',
  Investment:'💰',
  Gift:'🎁',
  Rental:'🏠', 'Rental Income':'🏠',
  Bonus:'🏆',
  Commission:'💵',
  'Side Income':'📦',
  Cashback:'💸',
  Refund:'↩️',
  Dividend:'📊',
  Interest:'🏦',
  Consulting:'👨‍💻',
  Other:'📝'
};
const AICONS = {...ECATS,...ICATS};

const UPI_METHODS = [
  { id:'gpay',   label:'Google Pay', icon:'💳', sub:'Pay using Google Pay' },
  { id:'phonepe',label:'PhonePe',    icon:'📲', sub:'Pay using PhonePe' },
  { id:'other',  label:'Other UPI',  icon:'🏦', sub:'Any other UPI app' },
];
const STATUSES = ['completed','pending','failed'];
const PAY_METHODS = ['cash','upi','card','other'];

async function loadExpenseCats(force=false){
  if(state.expenseCats && !force) return state.expenseCats;
  try{
    const d = await api('api/categories.php?type=expense');
    state.expenseCats = d.categories || [];
  }catch(e){ state.expenseCats = entries(ECATS).map(([k])=>({id:k,name:k})); }
  return state.expenseCats;
}

// ─── Expense view helpers ─────────────────────────────────────────────────────
const myExpenses = () => state.txs.filter(t=>t.type==='expense');
const sumAmt = list => list.reduce((s,t)=>s+t.amount,0);
const expYears = () => {
  const ys = new Set(myExpenses().map(t=>+t.date.slice(0,4)));
  ys.add(new Date().getFullYear());
  return [...ys].sort((a,b)=>b-a);
};

// ─── PDF Report data helpers (pure, no DOM) ──────────────────────────────────
const expOnly = list => list.filter(t=>t.type==='expense');
const txsOnDate = (list, date) => expOnly(list).filter(t=>t.date===date);
const txsInMonth = (list, m, y) => expOnly(list).filter(t=>+t.date.slice(0,4)===y && +t.date.slice(5,7)===m);
const txsInYear = (list, y) => expOnly(list).filter(t=>+t.date.slice(0,4)===y);
const totalAmt = list => list.reduce((s,t)=>s+(+t.amount||0),0);
const catTotals = list => { const m={}; list.forEach(t=>{ const k=t.cat||'Other'; m[k]=(m[k]||0)+(+t.amount||0); }); return m; };
const monthTotals = (list, y) => { const m={}; txsInYear(list,y).forEach(t=>{ const k=+t.date.slice(5,7); m[k]=(m[k]||0)+(+t.amount||0); }); return m; };
const pctOf = (amt, total) => total>0 ? Math.round((amt/total)*100) : 0;

// ─── PDF report builder (jsPDF) ──────────────────────────────────────────────
const pdfMoney = n => 'Rs. '+Math.abs(+n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
let _pdfLogoP = null;
const pdfLogo = () => {
  if(!_pdfLogoP) _pdfLogoP = (async()=>{
    try{
      const r = await fetch('Mlogo/MoneywiseLOGO.png?v=<?php echo $logov; ?>', { cache:'force-cache' });
      if(!r.ok) return null;
      const b = await r.blob();
      const url = await new Promise(res=>{ const fr=new FileReader(); fr.onload=()=>res(fr.result); fr.onerror=()=>res(null); fr.readAsDataURL(b); });
      if(!url) return null;
      const img = new Image();
      await new Promise((res,rej)=>{ img.onload=res; img.onerror=rej; img.src=url; });
      const size = 96;
      const ratio = Math.min(size/img.width, size/img.height, 1);
      const c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(img.width*ratio));
      c.height = Math.max(1, Math.round(img.height*ratio));
      c.getContext('2d').drawImage(img,0,0,c.width,c.height);
      return { url:c.toDataURL('image/png'), ratio:c.width/c.height };
    }catch(e){ return null; }
  })();
  return _pdfLogoP;
};
function pdfReady(){ return !!(window.jspdf && window.jspdf.jsPDF); }
// Tamil (and other Unicode) font embedding for PDFs. jsPDF's default
// helvetica/times fonts only carry WinAnsi glyphs, so Tamil would render as
// boxes. We load & embed a Unicode TTF (Noto Sans Tamil) into the generated PDF
// so Tamil is preserved on any device. Falls back gracefully if the font cannot
// be loaded (English-only output, never an error).
let _tamilFontLoaded = false;
let _tamilFontP = null;
async function ensureTamilFont(){
  if(_tamilFontLoaded) return true;
  if(!_tamilFontP) _tamilFontP = (async()=>{
    try{
      const r = await fetch('fonts/NotoSansTamil-Regular.ttf', {cache:'force-cache'});
      if(!r.ok) return false;
      const b = await r.blob();
      const url = await new Promise(res=>{ const fr=new FileReader(); fr.onload=()=>res(fr.result); fr.onerror=()=>res(null); fr.readAsDataURL(b); });
      if(!url) return false;
      _tamilFontB64 = String(url).split(',')[1]||null;
      _tamilFontLoaded = !!_tamilFontB64;
      return _tamilFontLoaded;
    }catch(e){ return false; }
  })();
  return _tamilFontP;
}
let _tamilFontB64 = null;
// Registers the Tamil font onto a specific jsPDF instance. jsPDF stores its VFS
// (virtual file-store) per-instance, so addFileToVFS/addFont must run against the
// doc itself (jsPDF.API.addFileToVFS fails before any doc exists). No-op if the
// font isn't cached yet — safe in any PDF.
function applyTamilFont(doc){
  if(!_tamilFontLoaded || !_tamilFontB64) return;
  try{
    doc.addFileToVFS('NotoSansTamil-Regular.ttf', _tamilFontB64);
    doc.addFont('NotoSansTamil-Regular.ttf','NotoSansTamil','normal');
  }catch(e){ /* ignore; English output still works */ }
}
const hasNonAscii = s => /[^\x00-\x7F]/.test(String(s==null?'':s));
// Pick Tamil font when the text actually contains non-ASCII glyphs and the font
// is loaded; otherwise revert to the standard helvetica (keeps the existing
// design for English content identical).
function setPdfFont(doc, text){
  if(_tamilFontLoaded && hasNonAscii(text)) doc.setFont('NotoSansTamil','normal');
  else doc.setFont('helvetica','normal');
}
// Bold-aware variant: the Tamil font only ships a single 'normal' weight, so for
// non-ASCII (Tamil) text we stay on NotoSansTamil normal; for ASCII we use the
// standard helvetica bold. This avoids jsPDF's broken single-arg setFont('bold'),
// which silently falls back to the 'times' font and breaks rendering.
function setPdfFontBold(doc, text){
  if(_tamilFontLoaded && hasNonAscii(String(text==null?'':text))) doc.setFont('NotoSansTamil','normal');
  else doc.setFont('helvetica','bold');
}
async function pdfOpen(title, subtitle){
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ unit:'mm', format:'a4' });
  const W = 210, M = 14;
  doc.setFillColor(124,58,237); doc.rect(0,0,W,30,'F');
  doc.setFillColor(167,139,250); doc.rect(0,27.5,W,1.5,'F');
  const logo = await pdfLogo();
  let bx = M;
  if(logo){ try{ const bw=17, bh=Math.min(17, bw/logo.ratio); doc.addImage(logo.url,'PNG',M,6.5+(17-bh)/2,bw,bh); }catch(e){} bx = M+21; }
  doc.setTextColor(255,255,255);
  doc.setFont('helvetica','bold'); doc.setFontSize(18);
  doc.text('MoneyWise', bx, 12.5);
  doc.setFont('helvetica','normal'); doc.setFontSize(9.5);
  doc.setTextColor(237,233,254);
  doc.text('Expense Report', bx, 18.5);
  doc.setFont('helvetica','bold'); doc.setFontSize(14);
  doc.setTextColor(255,255,255);
  doc.text(title, W-M, 12.5, { align:'right' });
  doc.setFont('helvetica','normal'); doc.setFontSize(9);
  doc.setTextColor(237,233,254);
  doc.text(subtitle, W-M, 18.5, { align:'right' });
  return { doc, W, M };
}
function pdfText(doc, s, x, y, maxW){
  let t = String(s==null?'':s);
  if(maxW && doc.getTextWidth(t) > maxW){
    while(t.length>1 && doc.getTextWidth(t+'…') > maxW) t = t.slice(0,-1);
    t += '…';
  }
  doc.text(t, x, y);
}
function pdfFooter(doc, W, M, note){
  const n = doc.internal.getNumberOfPages();
  const gen = new Date().toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
  for(let i=1;i<=n;i++){
    doc.setPage(i);
    doc.setDrawColor(233,233,250); doc.setLineWidth(0.3);
    doc.line(M, 284, W-M, 284);
    setPdfFont(doc, note); doc.setFontSize(8);
    doc.setTextColor(156,163,175);
    doc.text('MoneyWise • '+note, M, 289);
    doc.text('Generated '+gen, W-M, 289, { align:'right' });
    doc.text('Page '+i+' of '+n, W-M, 293.5, { align:'right' });
  }
}
function pdfTable(doc, W, M, y, head, rows, widths, opts={}){
  const bottom = 278, th = 7, rh = 6.5, pad = 1.5;
  const right = M + widths.reduce((a,b)=>a+b,0);
  const xs = []; let x = M; widths.forEach(w=>{ xs.push(x); x+=w; });
  const startNum = opts.startNum;
  const drawHead = () => {
    doc.setFillColor(124,58,237); doc.rect(M,y,right-M,th,'F');
    doc.setFont('helvetica','bold'); doc.setFontSize(8.5); doc.setTextColor(255,255,255);
    widths.forEach((w,i)=>{
      if(i===widths.length-1) doc.text(String(head[i]), xs[i]+w-pad, y+4.6, { align:'right' });
      else doc.text(String(head[i]), xs[i]+pad, y+4.6);
    });
    y += th;
  };
  drawHead();
  doc.setFont('helvetica','normal'); doc.setFontSize(8.5);
  rows.forEach((r,ri)=>{
    if(y+rh>bottom){ doc.addPage(); y=26; drawHead(); }
    const shade = ri%2 ? [245,243,255] : [255,255,255];
    doc.setFillColor(shade[0],shade[1],shade[2]); doc.rect(M,y,right-M,rh,'F');
    doc.setTextColor(30,27,46);
    widths.forEach((w,i)=>{
      let v = r[i];
      if(i===0 && startNum!==undefined) v = startNum+ri;
      if(i===widths.length-1){
        doc.setTextColor(220,38,38);
        doc.text(String(v), xs[i]+w-pad, y+4.6, { align:'right' });
      } else {
        const c = i===0 ? [124,58,237] : [30,27,46];
        doc.setTextColor(c[0],c[1],c[2]);
        pdfText(doc, v, xs[i]+pad, y+4.6, w-pad*2);
      }
    });
    y += rh;
  });
  return y;
}
function pdfSummaryBox(doc, W, M, y, label, value, rightNote){
  doc.setFillColor(245,243,255); doc.roundedRect(M, y, W-2*M, 22, 4, 4, 'F');
  setPdfFontBold(doc, label); doc.setFontSize(9); doc.setTextColor(124,58,237);
  doc.text(label, M+6, y+7.5);
  setPdfFont(doc, value); doc.setFontSize(15); doc.setTextColor(30,27,46);
  doc.text(value, M+6, y+17);
  if(rightNote){ setPdfFont(doc, rightNote); doc.setFontSize(8.5); doc.setTextColor(107,114,128); doc.text(rightNote, W-M-6, y+16, { align:'right' }); }
  return y+27;
}
function pdfEmptyBox(doc, W, M, y, msg){
  doc.setDrawColor(196,181,253); doc.setLineWidth(0.4);
  doc.roundedRect(M, y, W-2*M, 24, 4, 4, 'S');
  setPdfFont(doc, msg); doc.setFontSize(10); doc.setTextColor(124,58,237);
  doc.text(msg, W/2, y+14, { align:'center' });
  return y+30;
}
function pdfSection(doc, W, M, y, text){
  setPdfFontBold(doc, text); doc.setFontSize(11); doc.setTextColor(124,58,237);
  doc.text(text, M, y);
  const tw = doc.getTextWidth(text);
  doc.setDrawColor(221,214,254); doc.setLineWidth(0.4);
  doc.line(M+tw+4, y-1, W-M, y-1);
  return y+6;
}
// Builds the "Notes" column text for a transaction row in the PDF tables below,
// folding in payee/method/status/item details when present so itemised UPI
// entries show full detail without changing the existing table column layout.
function pdfDetailCell(t){
  const parts=[];
  if(t.payeeName) parts.push('Paid to: '+t.payeeName);
  if(t.items && t.items.length) parts.push(t.items.map(it=>it.name+' ('+(it.qty||'')+(it.unit?(' '+it.unit):'')+' × '+pdfMoney(it.unitPrice)+')').join(', '));
  if(t.paymentMethod && t.paymentMethod!=='cash') parts.push('via '+t.paymentMethod.toUpperCase());
  if(t.status && t.status!=='completed') parts.push('['+t.status.toUpperCase()+']');
  if(t.txnRef) parts.push('Ref: '+t.txnRef);
  if(t.notes) parts.push(t.notes);
  return parts.length?parts.join(' — '):'—';
}
async function downloadDailyPdf(dateStr){
  if(!pdfReady()){ toast('PDF library not loaded. Check your internet connection.'); return; }
  const list = txsOnDate(state.txs, dateStr).slice().sort((a,b)=>String(b.date).localeCompare(String(a.date)));
  const { doc, W, M } = await pdfOpen('Daily Expense Report', fmtDate(dateStr));
  let y = 38;
  y = pdfSummaryBox(doc, W, M, y, 'Total Expense for the Day', pdfMoney(totalAmt(list)), list.length+(list.length===1?' record':' records'));
  doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(75,85,99);
  doc.text('User: '+(state.user.name||''), M, y+2);
  doc.text('Date: '+fmtDate(dateStr), W-M, y+2, { align:'right' });
  y += 11;
  if(!list.length){
    pdfEmptyBox(doc, W, M, y, 'No expenses found for '+fmtDate(dateStr)+'.');
  } else {
    y += 2;
    y = pdfSection(doc, W, M, y, 'All Expenses for the Day');
    pdfTable(doc, W, M, y, ['#','Date','Category','Notes','Amount'],
      list.map(t=>[null, fmtDate(t.date), t.cat||'—', pdfDetailCell(t), pdfMoney(t.amount)]),
      [8, 26, 42, 64, 42], { startNum:1 });
  }
  pdfFooter(doc, W, M, 'Daily Expense Report • '+fmtDate(dateStr));
  doc.save('MoneyWise-Daily-Expenses-'+dateStr+'.pdf');
  toast('Daily PDF downloaded!');
}
async function downloadMonthlyPdf(m, y){
  if(!pdfReady()){ toast('PDF library not loaded. Check your internet connection.'); return; }
  const list = txsInMonth(state.txs, m, y).slice().sort((a,b)=>String(b.date).localeCompare(String(a.date)));
  const { doc, W, M } = await pdfOpen('Monthly Expense Report', MONTHS[m-1]+' '+y);
  let yy = 38;
  yy = pdfSummaryBox(doc, W, M, yy, 'Total Monthly Expense', pdfMoney(totalAmt(list)), list.length+(list.length===1?' record':' records'));
  doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(75,85,99);
  doc.text('User: '+(state.user.name||''), M, yy+2);
  doc.text('Period: '+MONTHS[m-1]+' '+y, W-M, yy+2, { align:'right' });
  yy += 11;
  if(!list.length){
    pdfEmptyBox(doc, W, M, yy, 'No expenses found for '+MONTHS[m-1]+' '+y+'.');
  } else {
    const total = totalAmt(list);
    const cats = entries(catTotals(list)).sort((a,b)=>b[1]-a[1]);
    yy += 2;
    yy = pdfSection(doc, W, M, yy, 'Category-wise Expense Details');
    yy = pdfTable(doc, W, M, yy, ['#','Category','% of Month','Amount'],
      cats.map(([k,v])=>[null, k, pctOf(v,total)+'%', pdfMoney(v)]),
      [8, 88, 40, 46], { startNum:1 });
    yy += 4;
    yy = pdfSection(doc, W, M, yy, 'Transactions');
    pdfTable(doc, W, M, yy, ['#','Date','Category','Notes','Amount'],
      list.map(t=>[null, fmtDate(t.date), t.cat||'—', pdfDetailCell(t), pdfMoney(t.amount)]),
      [8, 26, 42, 64, 42], { startNum:1 });
  }
  pdfFooter(doc, W, M, 'Monthly Expense Report • '+MONTHS[m-1]+' '+y);
  doc.save('MoneyWise-Monthly-Expenses-'+y+'-'+pad2(m)+'.pdf');
  toast('Monthly PDF downloaded!');
}
async function downloadYearlyPdf(y){
  if(!pdfReady()){ toast('PDF library not loaded. Check your internet connection.'); return; }
  const list = txsInYear(state.txs, y);
  const { doc, W, M } = await pdfOpen('Yearly Expense Report', 'Calendar Year '+y);
  let yy = 38;
  yy = pdfSummaryBox(doc, W, M, yy, 'Total Yearly Expense', pdfMoney(totalAmt(list)), list.length+(list.length===1?' record':' records'));
  doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(75,85,99);
  doc.text('User: '+(state.user.name||''), M, yy+2);
  doc.text('Year: '+y, W-M, yy+2, { align:'right' });
  yy += 11;
  if(!list.length){
    pdfEmptyBox(doc, W, M, yy, 'No expenses found for '+y+'.');
  } else {
    const total = totalAmt(list);
    const mt = monthTotals(state.txs, y);
    yy += 2;
    yy = pdfSection(doc, W, M, yy, 'Month-wise Expense Details');
    const mRows = [];
    for(let i=1;i<=12;i++) mRows.push([null, MONTHS[i-1], pctOf(mt[i]||0,total)+'%', pdfMoney(mt[i]||0)]);
    yy = pdfTable(doc, W, M, yy, ['#','Month','% of Year','Amount'], mRows, [8, 60, 44, 70], { startNum:1 });
    yy += 4;
    const cats = entries(catTotals(list)).sort((a,b)=>b[1]-a[1]);
    yy = pdfSection(doc, W, M, yy, 'Category-wise Expense Details');
    pdfTable(doc, W, M, yy, ['#','Category','% of Year','Amount'],
      cats.map(([k,v])=>[null, k, pctOf(v,total)+'%', pdfMoney(v)]),
      [8, 88, 40, 46], { startNum:1 });
  }
  pdfFooter(doc, W, M, 'Yearly Expense Report • '+y);
  doc.save('MoneyWise-Yearly-Expenses-'+y+'.pdf');
  toast('Yearly PDF downloaded!');
}
async function downloadCategoryPdf(y){
  if(!pdfReady()){ toast('PDF library not loaded. Check your internet connection.'); return; }
  const list = (y>0 ? txsInYear(state.txs, y) : expOnly(state.txs)).slice();
  const period = y>0 ? ('Calendar Year '+y) : 'Complete History';
  const { doc, W, M } = await pdfOpen('Category-wise Expense Report', period);
  let yy = 38;
  const grand = totalAmt(list);
  yy = pdfSummaryBox(doc, W, M, yy, 'Grand Total Expense', pdfMoney(grand), list.length+(list.length===1?' record':' records'));
  doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(75,85,99);
  doc.text('User: '+(state.user.name||''), M, yy+2);
  doc.text('Period: '+period, W-M, yy+2, { align:'right' });
  yy += 11;
  if(!list.length){
    pdfEmptyBox(doc, W, M, yy, 'No expenses found for '+period+'.');
  } else {
    const byCat = {};
    list.forEach(t=>{ const k=t.cat||'Other'; (byCat[k]=byCat[k]||[]).push(t); });
    const catNames = Object.keys(byCat).sort((a,b)=>totalAmt(byCat[b])-totalAmt(byCat[a]));
    yy += 2;
    catNames.forEach(cat=>{
      const rows = byCat[cat];
      const catTotal = totalAmt(rows);
      if(yy>250){ doc.addPage(); yy=26; }
      yy = pdfSection(doc, W, M, yy, cat.toUpperCase()+' — '+pdfMoney(catTotal));
      yy = pdfTable(doc, W, M, yy, ['#','Date','Details','Amount'],
        rows.map(t=>[null, fmtDate(t.date), pdfDetailCell(t), pdfMoney(t.amount)]),
        [8, 26, 106, 40], { startNum:1 });
      yy += 6;
    });
  }
  pdfFooter(doc, W, M, 'Category-wise Expense Report • '+period);
  doc.save('MoneyWise-Category-Expenses-'+(y>0?y:'all')+'.pdf');
  toast('Category-wise PDF downloaded!');
}
function sectionTitle(t){
  return h('div',{class:'sh',style:'margin-top:2px'},h('h3',{},t));
}
function mstat(label, value, icon, valCls=''){
  return h('div',{class:'mstat'},
    h('div',{class:'lbl'}, icon?h('i',{class:`fas ${icon}`,style:'margin-right:6px;color:var(--p3);font-size:12px'}):null, label),
    h('div',{class:`val ${valCls}`},value)
  );
}
function barList(items, total, iconOf, labelWidth=75){
  const sorted=entries(items).sort((a,b)=>b[1]-a[1]);
  const bk=h('div',{class:'card'});
  sorted.forEach(([key,amt])=>{
    const row=h('div',{class:'cat-row'});
    row.appendChild(h('span',{style:'font-size:19px'},iconOf?iconOf(key):(ECATS[key]||'📋')));
    row.appendChild(h('span',{style:`font-size:12px;font-weight:700;width:${labelWidth}px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis`},key));
    const bw=h('div',{class:'cbw'}); const bar=h('div',{class:'cb',style:'width:0'});
    bw.appendChild(bar); row.appendChild(bw);
    row.appendChild(h('span',{class:'cpct'},Math.round((amt/total)*100)+'%'));
    row.appendChild(h('span',{style:'font-size:12px;font-weight:700;color:#dc2626;margin-left:7px;min-width:65px;text-align:right'},fmt(amt)));
    bk.appendChild(row);
    setTimeout(()=>{ bar.style.width=((amt/total)*100)+'%'; },80);
  });
  return bk;
}
function txRow(tx){
  const ti=h('div',{class:'tx'});
  ti.appendChild(h('div',{class:`tx-ic ${tx.type}`},AICONS[tx.cat]||'📋'));
  const bd=h('div',{class:'tx-bd'});
  bd.appendChild(h('div',{class:'tx-cat'},tx.cat));
  if(tx.notes) bd.appendChild(h('div',{class:'tx-nt'},tx.notes));
  ti.appendChild(bd);
  ti.appendChild(h('div',{class:`tx-am ${tx.type}`},(tx.type==='income'?'+':'-')+fmt(tx.amount)));
  return ti;
}
function catList(map, type){
  const card=h('div',{class:'card',style:'padding:8px 0'});
  entries(map).sort((a,b)=>b[1]-a[1]).forEach(([k,amt])=>{
    const row=h('div',{class:'cat-list-row'});
    row.appendChild(h('span',{class:'cli'},AICONS[k]||'📋'));
    row.appendChild(h('span',{class:'cln'},k));
    row.appendChild(h('span',{class:type==='income'?'cla gr':'cla rd'},fmt(amt)));
    card.appendChild(row);
  });
  return card;
}
function dsTile(label, val, icon){
  return h('div',{class:'ds-c'},
    h('div',{class:'ds-lbl'},h('i',{class:`fas ${icon}`,style:'margin-right:5px;color:var(--p3);font-size:11px'}),label),
    h('div',{class:'ds-val'},fmt(val))
  );
}

// ─── Global state (mirrors what the PHP API returns) ────────────────────────
const state = {
  user: null,      // { id, name, email, is_admin }
  txs: [],         // own transactions
  mySalary: null,  // own salary { amount, month, year } | null
  employees: [],   // admin only: [{ id, name, email, amount, month, year }]
  history: [],     // admin only: salary history records
  years: [],       // admin only: available years for filters
  adminUsers: [],  // admin only: [{ id, name, email, is_admin }]
  clearedTxs: new Set(), // server-persisted hidden transaction ids (Status module etc., via api/clear.php)
  dashBalanceCleared: false, // server-persisted Dashboard "balance erased" flag (api/clear.php)
  expenseCats: null, // lazily-loaded [{id,name}] from api/categories.php (built-in + custom)
  events: [],        // own event summary cards (from api/events.php?action=list)
  eventYears: [],    // available years for the event filters
};

let page = 'login';
let authPage = 'login';
let settingsPage = null;
let profileTab = 'profile';
let statusMonth = new Date().getMonth()+1;
let statusYear  = new Date().getFullYear();

const VALID_PAGES = ['dashboard','expense','income','add-expense','add-income','stats','profile','settings','tx-history','events','calc'];

function setPage(p){
  page = p;
  if(VALID_PAGES.includes(p)){
    history.replaceState(null,'','#'+p);
  }
}

function getPageFromHash(){
  const h = location.hash.replace('#','');
  return VALID_PAGES.includes(h) ? h : null;
}

// ─── Event Expense Module state ─────────────────────────────────────────────
const EVENT_TYPES = ['Marriage','Birthday','Housewarming','Travel','Festival','Party','Function','Medical','Education','Shopping','Business','Other'];
const EVENT_PAY_METHODS = ['Cash','UPI','GPay','PhonePe','Bank Transfer','Debit Card','Credit Card','Other'];
let eventSub = 'list';      // 'list' | 'create' | 'edit' | 'detail'
let activeEvent = null;     // full detail payload { event, expenses, total, count, itemTotals, paymentTotals, remaining, budgetExceeded }
let activeEventId = null;
let eventFilters = { type:'', name:'', month:'', year:'' };

// ─── Calculator module state ─────────────────────────────────────────────────
let calcExpr = '';          // calculator entry, kept across re-renders
let calcResult = '';        // live preview / last answer
let calcError = '';
let calcOp = '';            // pending operator ('+','-','*','/') for iPhone highlight
let calcStartNew = false;   // after '=', the next digit begins a new calculation

// ─── TANGLISH → TAMIL ─────────────────────────────────────────────────────────
// Reusable Tanglish (Tamil typed in Latin) → Tamil Unicode converter used by the
// Event module. It combines a curated dictionary of common grocery/domestic words
// (exact Tamil + a confident emoji) with a general rule-based phonetic
// transliterator for arbitrary words. Only dictionary matches get an emoji, so we
// never attach random emoji to unknown text.
const TA_PULLI = '்';
const TA_VOWELS = { a:'அ', aa:'ஆ', i:'இ', ii:'ஈ', u:'உ', uu:'ஊ', e:'எ', ee:'ஏ', ai:'ஐ', o:'ஒ', oo:'ஓ', ou:'ஔ', au:'ஔ' };
const TA_VOWEL_SIGNS = { a:'', aa:'ா', i:'ி', ii:'ீ', u:'ு', uu:'ூ', e:'ெ', ee:'ே', ai:'ை', o:'ொ', oo:'ோ', ou:'ௌ', au:'ௌ' };
const TA_CONS = {
  k:'க', kh:'க', g:'க', gh:'க', kk:'க', gg:'க', ng:'ங', q:'க',
  c:'ச', ch:'ச', j:'ஜ', ny:'ஞ', nj:'ஞ', gn:'ஞ',
  t:'ட', th:'த', d:'ட', dh:'த', n:'ந', nn:'ண',
  p:'ப', ph:'ப', f:'ப', b:'ப', bh:'ப', m:'ம',
  y:'ய', r:'ர', l:'ல', v:'வ', w:'வ', z:'ழ', zh:'ழ', s:'ஸ', sh:'ஷ', S:'ஷ', h:'ஹ', ksh:'க்ஷ', x:'க்ஷ'
};
const TA_CONS_CHARS = new Set('kghjnytdpcbsrlmvzxhNqwf'.split(''));
const TA_VOWEL_CHARS = new Set('aeiou'.split(''));
const TA_DIGRAPHS = ['ksh','kh','gh','ng','ch','nj','ny','gn','th','dh','sh','zh','bh','ph','kk','gg','nn','ll','rr','tt','pp','aa','ii','uu','ee','oo','ai','au','ou'];

function taSplitPhonemes(word){
  const out = []; const lower = word.toLowerCase(); let i = 0; const n = lower.length;
  while(i < n){
    let found = null;
    for(const d of TA_DIGRAPHS){
      if(lower.startsWith(d, i)){
        if(d in TA_CONS) found = {type:'c', value:d, len:2};
        else if(d==='aa'||d==='ii'||d==='uu'||d==='ee'||d==='oo'||d==='ai'||d==='au'||d==='ou') found = {type:'v', value:d, len:2};
        break;
      }
    }
    if(!found){
      const ch = lower[i];
      if(TA_VOWEL_CHARS.has(ch)) found = {type:'v', value:ch, len:1};
      else if(TA_CONS_CHARS.has(ch)) found = {type:'c', value:ch, len:1};
      else found = {type:'x', value:ch, len:1, raw:ch};
    }
    out.push(found); i += found.len;
  }
  return out;
}

function tanglishWordToTamil(word){
  const units = taSplitPhonemes(word);
  let out = '', pending = false;
  for(let k=0;k<units.length;k++){
    const u = units[k];
    if(u.type==='x'){ out += u.raw||u.value; pending=false; continue; }
    if(u.type==='v'){
      if(!pending) out += TA_VOWELS[u.value] || u.value;
      else out += TA_VOWEL_SIGNS[u.value] ?? '';
      pending = false; continue;
    }
    const base = TA_CONS[u.value] || u.value;
    const next = units[k+1];
    const nextIsVowel = next && next.type==='v';
    if(!nextIsVowel){ out += base + TA_PULLI; pending=false; }
    else { out += base; pending=true; }
  }
  return out;
}

// Common words with confident, curated Tamil + emoji. Extend this list over time;
// the rule engine below still converts any unlisted Tanglish word.
const TANGLISH_DICT = {
  // ── people / family ──
  'amma':{ta:'அம்மா'}, 'appa':{ta:'அப்பா'}, 'akka':{ta:'அக்கா'}, 'thambi':{ta:'தம்பி'},
  'thangai':{ta:'தங்கை'}, 'annan':{ta:'அண்ணன்'}, 'anna':{ta:'அண்ணா'}, 'paati':{ta:'பாட்டி'},
  'thaatha':{ta:'தாத்தா'}, 'mama':{ta:'மாமா'}, 'maami':{ta:'மாமி'}, 'pappa':{ta:'பாப்பா'},
  'kuzhandhai':{ta:'குழந்தை'}, 'pillai':{ta:'பிள்ளை'}, 'ponnu':{ta:'பொண்ணு'}, 'payyan':{ta:'பையன்'},
  'paiyan':{ta:'பையன்'}, 'magal':{ta:'மகள்'}, 'magan':{ta:'மகன்'}, 'kudumbam':{ta:'குடும்பம்'},
  'kutumbam':{ta:'குடும்பம்'}, 'veetu':{ta:'வீட்டு'}, 'photos':{ta:'போட்டோ'},
  // ── food / kitchen ──
  'thanni':{ta:'தண்ணீர்',emo:'💧'}, 'tanni':{ta:'தண்ணீர்',emo:'💧'}, 'thaneer':{ta:'தண்ணீர்',emo:'💧'}, 'thanneer':{ta:'தண்ணீர்',emo:'💧'},
  'soru':{ta:'சோறு',emo:'🍚'}, 'saapadu':{ta:'சாப்பாடு'}, 'saapaadu':{ta:'சாப்பாடு'}, 'saapadu':{ta:'சாப்பாடு'},
  'unavu':{ta:'உணவு'}, 'thuvaiyal':{ta:'துவையல்'}, 'rasam':{ta:'ரசம்'}, 'sambar':{ta:'சாம்பார்'},
  'curry':{ta:'கறி'}, 'masala':{ta:'மசாலா'}, 'palagaram':{ta:'பலகாரம்'}, 'sweet':{ta:'இனிப்பு'},
  'paal':{ta:'பால்',emo:'🥛'}, 'venna':{ta:'வெண்ணெய்'}, 'thayiru':{ta:'தயிர்'}, 'mor':{ta:'மோர்'},
  'egg':{ta:'முட்டை'}, 'muttai':{ta:'முட்டை'}, 'kadai kozhi':{ta:'நாட்டுக்கோழி'}, 'kozhi':{ta:'கோழி'},
  'meen':{ta:'மீன்'}, 'iraichi':{ta:'இறைச்சி'}, 'aadu':{ta:'ஆடு'}, 'mutton':{ta:'ஆட்டிறைச்சி'},
  'kaapi':{ta:'காபி',emo:'☕'}, 'coffee':{ta:'காபி',emo:'☕'}, 'tea':{ta:'தேநீர்'}, 'paalation':{ta:'பால்'}, 'aalu':{ta:'உருளைக்கிழங்கு'},
  'manga':{ta:'மாங்காய்',emo:'🥭'}, 'maanga':{ta:'மாங்காய்',emo:'🥭'}, 'maangai':{ta:'மாங்காய்',emo:'🥭'},
  'thakaali':{ta:'தக்காளி',emo:'🍅'}, 'takkali':{ta:'தக்காளி',emo:'🍅'}, 'thakkali':{ta:'தக்காளி',emo:'🍅'},
  'vengayam':{ta:'வெங்காயம்'}, 'poondu':{ta:'பூண்டு'}, 'inji':{ta:'இஞ்சி'}, 'milagai':{ta:'மிளகாய்'},
  'karunai kilangu':{ta:'கருணைக்கிழங்கு'}, 'muttaikose':{ta:'முட்டைக்கோஸ்'}, 'beetroot':{ta:'பீட்ரூட்'},
  'carrot':{ta:'கேரட்'}, 'beans':{ta:'பீன்ஸ்'}, 'payir':{ta:'பயிறு'}, 'verkaadalai':{ta:'வேர்க்கடலை'},
  'semiya':{ta:'சேமியா'}, 'arisi':{ta:'அரிசி'}, 'godhumai':{ta:'கோதுமை'}, 'maavu':{ta:'மாவு'},
  'nalla ennai':{ta:'நல்லெண்ணெய்'}, 'oil':{ta:'எண்ணெய்'}, 'uppu':{ta:'உப்பு'}, 'sakkarai':{ta:'சக்கரை'},
  'sarkarai':{ta:'சர்க்கரை'}, 'paagu':{ta:'பாகு'}, 'rosu':{ta:'உப்புமா'}, 'idli':{ta:'இட்லி'},
  'dosai':{ta:'தோசை'}, 'dosa':{ta:'தோசை'}, 'poori':{ta:'பூரி'}, 'chappathi':{ta:'சப்பாத்தி'},
  'parotta':{ta:'பரோட்டா'}, 'briyani':{ta:'பிரியாணி'}, 'biriyani':{ta:'பிரியாணி'}, 'pulao':{ta:'புலாவ்'},
  // ── fruits / veg ──
  'apple':{ta:'ஆப்பிள்',emo:'🍎'}, 'vaazhai':{ta:'வாழைப்பழம்',emo:'🍌'}, 'banana':{ta:'வாழைப்பழம்',emo:'🍌'},
  'thiratchai':{ta:'திராட்சை',emo:'🍇'}, 'grapes':{ta:'திராட்சை',emo:'🍇'}, 'orange':{ta:'ஆரஞ்சு',emo:'🍊'},
  'annaasi':{ta:'அன்னாசி',emo:'🍍'}, 'pineapple':{ta:'அன்னாசி',emo:'🍍'}, 'pomegranate':{ta:'மாதுளை'},
  'maathulai':{ta:'மாதுளை'}, 'guava':{ta:'கொய்யா'}, 'koiyaa':{ta:'கொய்யா'}, 'mango':{ta:'மாங்காய்',emo:'🥭'},
  'elumichai':{ta:'எலுமிச்சை'}, 'nimbu':{ta:'எலுமிச்சை'}, 'kathirikkai':{ta:'கத்தரிக்காய'}, 'vendakkai':{ta:'வெண்டைக்காய'},
  'avaraikkai':{ta:'அவரைக்காய'}, 'poosanikkai':{ta:'பூசணிக்காய'}, 'patthakkaai':{ta:'புடலங்காய'}, 'kakkarikaai':{ta:'வெள்ளரிக்காய'},
  'vellarikkai':{ta:'வெள்ளரிக்காய'}, 'murungaikai':{ta:'முருங்கைக்காய'}, 'keerai':{ta:'கீரை'},
  // ── places / travel / events ──
  'chennai':{ta:'சென்னை'}, 'madurai':{ta:'மதுரை'}, 'coimbatore':{ta:'கோயம்புத்தூர்'}, 'kovai':{ta:'கோவை'},
  'tirunelveli':{ta:'திருநெல்வேலி'}, 'salem':{ta:'சேலம்'}, 'trichy':{ta:'திருச்சி'}, 'thanjavur':{ta:'தஞ்சாவூர்'},
  'vellore':{ta:'வேலூர்'}, 'pondicherry':{ta:'புதுச்சேரி'}, 'kerala':{ta:'கேரளா'}, 'bangalore':{ta:'பெங்களூரு'},
  'mumbai':{ta:'மும்பை'}, 'delhi':{ta:'டெல்லி'}, 'ooty':{ta:'ஊட்டி'}, 'kodaikanal':{ta:'கொடைக்கானல்'},
  'kovil':{ta:'கோவில்'}, 'temple':{ta:'கோவில்'}, 'koil':{ta:'கோயில்'}, 'thiruvizha':{ta:'திருவிழா'},
  'thiruvilaiyadal':{ta:'திருவிழா'}, 'theevu':{ta:'தீவு'}, 'beach':{ta:'கடற்கரை'}, 'kadal':{ta:'கடல்'},
  'mumbai':{ta:'மும்பை'}, 'bus':{ta:'பேருந்து'}, 'train':{ta:'ரயில்'}, 'van':{ta:'வேன்'},
  // ── celebration / events ──
  'kalyanam':{ta:'திருமணம்'}, 'marriage':{ta:'திருமணம்'}, 'wedding':{ta:'திருமணம்'}, 'pandigai':{ta:'பண்டிகை'},
  'pongal':{ta:'பொங்கல்'}, 'deepavali':{ta:'தீபாவளி'}, 'diwali':{ta:'தீபாவளி'}, 'karthigai':{ta:'கார்த்திகை'},
  'ayudha poojai':{ta:'ஆயுத பூஜை'}, 'pongal':{ta:'பொங்கல்'}, 'kolu':{ta:'கொலு'}, 'kolam':{ta:'கோலம்'},
  'thurutu':{ta:'தோரணம்'}, 'vaazhthukkal':{ta:'வாழ்த்துக்கள்'}, 'vaaztha':{ta:'வாழ்த்து'}, 'parisam':{ta:'பரிசு'}, 'gift':{ta:'பரிசு'}, 'dress':{ta:'ஆடை'}, 'sari':{ta:'புடவை'}, 'saree':{ta:'புடவை'},
  // ── common words / pronouns / time ──
  'nandri':{ta:'நன்றி'}, 'vanakkam':{ta:'வணக்கம்',emo:'🙏'}, 'kaadhal':{ta:'காதல்',emo:'❤️'}, 'poo':{ta:'பூ',emo:'🌸'},
  'naalai':{ta:'நாளை'}, 'kaalai':{ta:'காலை'}, 'mani':{ta:'மணி'}, 'ratri':{ta:'இரவு'}, 'iravu':{ta:'இரவு'},
  'naal':{ta:'நாள்'}, 'pakal':{ta:'பகல்'}, 'maasam':{ta:'மாதம்'}, 'varusham':{ta:'வருடம்'}, 'aandu':{ta:'ஆண்டு'},
  'vandru':{ta:'வந்து'}, 'vandhu':{ta:'வந்து'}, 'vandha':{ta:'வந்த'}, 'pogu':{ta:'போ'}, 'poi':{ta:'போய்'},
  'vanga':{ta:'வாங்க'}, 'varen':{ta:'வரேன்'}, 'vangitu':{ta:'வாங்கிட்டு'}, 'vangittu':{ta:'வாங்கிட்டு'},
  'vendum':{ta:'வேண்டும்'}, 'venum':{ta:'வேணும்'}, 'thevai':{ta:'தேவை'}, 'venam':{ta:'வேண்டாம்'},
  'aama':{ta:'ஆமாம்'}, 'illa':{ta:'இல்லை'}, 'pala':{ta:'பல'}, 'silu':{ta:'சில'}, 'ellaam':{ta:'எல்லாம்'},
  'avangal':{ta:'அவர்கள்'}, 'ivangal':{ta:'இவர்கள்'}, 'nama':{ta:'நம்ம'}, 'namba':{ta:'நம்ப'},
  'kooda':{ta:'கூட'}, 'ottu':{ta:'ஒட்டு'}, 'poda':{ta:'போடா'}, 'vaa':{ta:'வா'},
  'pom':{ta:'போம்'}, 'povom':{ta:'போவோம்'}, 'theerpom':{ta:'தீர்ப்போம்'},
  'breakfast':{ta:'காலை உணவு'}, 'lunch':{ta:'மதிய உணவு'}, 'bunch':{ta:'மதிய உணவு'}, 'dinner':{ta:'இரவு உணவு'},
  'hall':{ta:'மண்டபம்'}, 'koottam':{ta:'கூட்டம்'}, 'kalluri':{ta:'கல்லூரி'}, 'pallikoodam':{ta:'பள்ளிக்கூடம்'},
  // ── domestic / shopping / expense items ──
  'pooja':{ta:'பூஜை'}, 'thuni':{ta:'துணி'}, 'vattam':{ta:'வட்டம்'}, 'kudasa':{ta:'குடை'},
  'kudai':{ta:'குடை'}, 'kattil':{ta:'கட்டில்'}, 'table':{ta:'மேசை'}, 'chair':{ta:'நாற்காலி'},
  'sofa':{ta:'சோபா'}, 'fan':{ta:'மின்விசிறி'}, 'light':{ta:'விளக்கு'}, 'vilakku':{ta:'விளக்கு'},
  'fridge':{ta:'குளிர்சாதனப்பெட்டி'}, 'tv':{ta:'தொலைக்காட்சி'}, 'mobile':{ta:'மொபைல்'}, 'phone':{ta:'போன்'},
  'laptop':{ta:'லேப்டாப்'}, 'money':{ta:'பணம்'}, 'panam':{ta:'பணம்'}, 'kasu':{ta:'காசு'}, 'selavu':{ta:'செலவு'},
  'saffron':{ta:'குங்குமம்'}, 'kungumam':{ta:'குங்குமம்'}, 'manjal':{ta:'மஞ்சள்'}, 'veethai':{ta:'விதை'},
  // ── days / misc ──
  'thingal':{ta:'திங்கள்'}, 'sevvai':{ta:'செவ்வாய்'}, 'budhan':{ta:'புதன்'}, 'viyazhan':{ta:'வியாழன்'},
  'velli':{ta:'வெள்ளி'}, 'sani':{ta:'சனி'}, 'nyayiru':{ta:'ஞாயிறு'}, 'nalla':{ta:'நல்ல'}, 'periya':{ta:'பெரிய'},
  'china':{ta:'சிறிய'}, 'chinna':{ta:'சின்ன'}, 'pudhu':{ta:'புது'}, 'pazhaya':{ta:'பழைய'}, 'veera':{ta:'வீர'},
  'sollu':{ta:'சொல்லு'}, 'kelu':{ta:'கேளு'}, 'eduthu':{ta:'எடுத்து'}, 'kodu':{ta:'கொடு'},
  'nalla':{ta:'நல்ல'}, 'sari':{ta:'சரி'}, 'polama':{ta:'போலாமா'}, 'polaam':{ta:'போலாம்'}
};

// Convert Tanglish within a string. Already-Tamil / non-Latin text is left intact.
// Only words in TANGLISH_DICT receive an emoji (length + meaning confidence).
function tanglishToTamil(text){
  if(!text || !/[a-zA-Z]/.test(text)) return text;
  return String(text).split(/(\s+)/).map(tok=>{
    if(/^\s*$/.test(tok) || !/[a-zA-Z]/.test(tok)) return tok;
    return tok.replace(/([a-zA-Z]+)/g, (m)=>{
      const key = m.toLowerCase();
      if(TANGLISH_DICT[key]){
        const w = TANGLISH_DICT[key];
        return (w.ta || tanglishWordToTamil(m)) + (w.emo ? ' '+w.emo : '');
      }
      return tanglishWordToTamil(m);
    });
  }).join('');
}

// ─── ENGLISH + TANGLISH → TAMIL PIPELINE (Tamil toggle) ──────────────────────
// The per-event Tamil toggle drives this pipeline. It detects the input type and
// produces PROPER Tamil (not just transliteration):
//   • Already-Tamil text                     → unchanged (never corrupted)
//   • Numbers / ₹ / % / dates / times /
//     emails / URLs / transaction IDs        → preserved (never translated)
//   • Tanglish (romanized Tamil)             → local dictionary/transliteration
//   • Genuine English                        → browser-side keyless Google
//     translate (high quality), falling back to api/translate.php (keyed →
//     MyMemory → small offline dictionary) when gtx is unavailable/offline.
const TAMIL_PRESERVE_ALWAYS = /^(?:₹[\d.,]+%?|[\d.,]+\s*(?:%|₹|\$|€|£)|[\d.,]+|\d[\d.,]*(?:st|nd|rd|th)|%|₹|\$|€|£)$/;
const isProtectedToken = (p) => {
  if (!/[a-zA-Z]/.test(p)) return false;
  if (TAMIL_PRESERVE_ALWAYS.test(p.replace(/\s+/g,''))) return true;
  if (/^\d{1,2}:\d{2}(?::\d{2})?\s*(?:am|pm)?$/i.test(p)) return true;
  if (/^\d{1,4}[-/]\d{1,2}[-/]\d{1,4}$/.test(p)) return true;
  if (/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i.test(p)) return true;
  if (/^https?:\/\/\S+$/i.test(p)) return true;
  if (/^(?:[A-Z]{2,}[0-9]{2,}|[A-Z0-9]{8,})$/.test(p)) return true;
  return false;
};
async function gtxTranslate(text){
  try{
    const url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl=ta&dt=t&q=' + encodeURIComponent(text);
    const res = await fetch(url, { headers: { 'Accept':'application/json' } });
    if(!res.ok) return null;
    const data = await res.json();
    if(Array.isArray(data) && Array.isArray(data[0])){
      let out = '';
      for(const seg of data[0]){ if(seg && Array.isArray(seg) && typeof seg[0]==='string') out += seg[0]; }
      out = out.trim();
      return (out && out !== text) ? out : null;
    }
  }catch(e){}
  return null;
}
async function serverTranslate(text){
  try{
    const d = await api('api/translate.php', { method:'POST', body: JSON.stringify({ text }) });
    return d && d.ok && d.text != null ? d.text : null;
  }catch(e){ return null; }
}
// Translate one English phrase. Prefers the high-quality keyless browser gtx,
// then the server endpoint (keyed/MyMemory/dictionary fallback).
async function translateEnglish(phrase){
  const g = await gtxTranslate(phrase);
  if(g) return g;
  const s = await serverTranslate(phrase);
  if(s) return s;
  return null;
}
// Main entry: convert user-entered text to proper Tamil (toggle ON).
async function translateToTamil(text){
  const str = String(text == null ? '' : text);
  if(!str) return '';
  if(/[\u0B80-\u0BFF]/.test(str)) return str;          // already Tamil
  if(!/[a-zA-Z]/.test(str)) return str;                // pure numbers/symbols

  const parts = str.split(/(\s+)/);
  const kinds = parts.map(p=>{
    if(/^\s+$/.test(p)) return 'sep';
    if(!/[a-zA-Z]/.test(p)) return 'keep';
    if(isProtectedToken(p)) return 'keep';
    const key = p.replace(/[^a-zA-Z]/g,'').toLowerCase();
    if(TANGLISH_DICT[key]) return 'tanglish';
    return 'en';
  });

  const out = new Array(parts.length).fill('');
  for(let i=0;i<parts.length;i++){
    if(kinds[i]==='sep' || kinds[i]==='keep') out[i] = parts[i];
    else if(kinds[i]==='tanglish') out[i] = tanglishToTamil(parts[i]);
  }

  // Group contiguous English words (across single spaces) and translate each run
  // as a whole phrase for better quality, then map words back in order.
  let i = 0;
  while(i < parts.length){
    if(kinds[i] !== 'en'){ i++; continue; }
    let j = i, words = [];
    while(j < parts.length && (kinds[j]==='en' || (kinds[j]==='sep' && parts[j].trim()==='' && j+1<parts.length && kinds[j+1]==='en'))){
      if(kinds[j]==='en') words.push(parts[j]);
      j++;
    }
    const phrase = words.join(' ');
    let trans = null;
    try{ trans = await translateEnglish(phrase); }catch(e){}
    const tw = trans ? String(trans).split(/\s+/) : words;
    let wi = 0;
    for(let k=i;k<j;k++){
      if(kinds[k]==='en'){
        out[k] = (tw[wi] != null && tw[wi] !== '') ? tw[wi] : parts[k];
        wi++;
      }
    }
    i = j;
  }
  return out.join('');
}

// ─── EVENT MODULE STATE ───────────────────────────────────────────────────────

// ─── API helper ─────────────────────────────────────────────────────────────
async function api(url, opts = {}) {
  const res = await fetch(url, {
    headers: {'Content-Type':'application/json'},
    credentials: 'same-origin',
    ...opts,
  });
  let data;
  try { data = await res.json(); } catch (e) { data = { ok:false, error:'Invalid server response.' }; }
  if (!data.ok) throw new Error(data.error || ('Request failed (' + res.status + ')'));
  return data;
}

async function refreshAll() {
  const d = await api('api/transactions.php');
  state.txs = d.transactions;
  const clr = await api('api/clear.php');
  state.clearedTxs = new Set((clr.cleared||[]).map(Number));
  state.dashBalanceCleared = clr.balance_cleared === true;
  const mine = await api('api/salary.php?action=mine');
  state.mySalary = mine.salary;
  if (state.user && state.user.is_admin === 1) {
    const sal = await api('api/salary.php?action=list');
    state.employees = sal.employees;
    const hist = await api('api/salary.php?action=history');
    state.history = hist.history;
    state.years = hist.years;
    const users = await api('api/admin.php?action=users');
    state.adminUsers = users.users;
  } else {
    state.employees = [];
    state.history = [];
    state.years = [];
    state.adminUsers = [];
  }
}

async function logout() {
  try { await api('api/auth.php', { method:'POST', body: JSON.stringify({ action:'logout' }) }); } catch (e) {}
  state.user = null;
  state.txs = []; state.mySalary = null; state.employees = []; state.history = []; state.years = []; state.adminUsers = []; state.clearedTxs = new Set(); state.dashBalanceCleared = false;
  calcExpr = ''; calcResult = ''; calcError = ''; calcOp = ''; calcStartNew = false;
  aiCloseOverlay();
  page = 'login'; authPage = 'login'; location.hash=''; render();
}

function toast(msg) {
  const t = h('div',{class:'al al-s fade',style:'position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:300;width:90%;max-width:390px;box-shadow:0 4px 20px rgba(22,163,74,.2)'},h('i',{class:'fas fa-circle-check'}),' '+msg);
  document.body.appendChild(t);
  setTimeout(()=>t.remove(), 3000);
}

// ─── Persistent Clear (Status module etc.) ─────────────────────────────────────
// Hides the listed transaction ids from the UI. The ids are stored server-side
// in `cleared_items` (api/clear.php) so the cleared state survives refresh,
// browser reopen and logout/login. The original records are NEVER deleted.
function isTxCleared(tx){ return state.clearedTxs.has(+tx.id); }
function visibleTxs(list){ return (list||[]).filter(t=>!isTxCleared(t)); }
async function persistClear(txIds){
  const ids=[...new Set((txIds||[]).map(id=>+id))].filter(id=>Number.isInteger(id)&&id>0);
  if(!ids.length) return;
  const d=await api('api/clear.php',{method:'POST',body:JSON.stringify({action:'clear',tx_ids:ids})});
  (d.cleared||[]).forEach(id=>state.clearedTxs.add(+id));
}
async function persistRestore(txIds){
  const ids=[...new Set((txIds||[]).map(id=>+id))].filter(id=>Number.isInteger(id)&&id>0);
  if(!ids.length) return;
  const d=await api('api/clear.php',{method:'POST',body:JSON.stringify({action:'restore',tx_ids:ids})});
  (d.restored||[]).forEach(id=>state.clearedTxs.delete(+id));
}

// ─── Persistent Dashboard balance-erase flag ───────────────────────────────────
// Display-only: sets/unsets the user's "balance erased" flag via api/clear.php so
// it survives refresh and logout/login. Records are NEVER touched. The flag is
// auto-reset server-side when a new transaction is added (api/transactions.php).
async function persistBalanceClear(cleared){
  const d=await api('api/clear.php',{method:'POST',body:JSON.stringify({action:cleared?'clear_balance':'restore_balance'})});
  state.dashBalanceCleared = d.balance_cleared === true;
}

function confirmClear(msg, onConfirm){
  const overlay=h('div',{class:'modal-overlay'});
  const sheet=h('div',{class:'modal-sheet'});
  sheet.appendChild(h('div',{class:'modal-handle'}));
  sheet.appendChild(h('div',{class:'modal-title'},'🧹 Clear View'));
  sheet.appendChild(h('div',{style:'background:#fffbeb;border-radius:var(--r-lg);padding:12px 14px;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;color:#92400e;font-size:13px;line-height:1.5'},h('i',{class:'fas fa-triangle-exclamation',style:'color:#d97706;margin-top:2px'}),h('div',{},msg)));
  const clearBtn=h('button',{class:'btn btn-d'},h('i',{class:'fas fa-broom'}),' Clear');
  clearBtn.addEventListener('click',()=>{ overlay.remove(); onConfirm(); });
  const cancelBtn=h('button',{class:'btn',style:'background:var(--p5);color:var(--mid);margin-top:8px'},h('i',{class:'fas fa-times'}),' Cancel');
  cancelBtn.addEventListener('click',()=>overlay.remove());
  sheet.appendChild(clearBtn); sheet.appendChild(cancelBtn);
  overlay.appendChild(sheet);
  overlay.addEventListener('click',e=>{ if(e.target===overlay) overlay.remove(); });
  document.body.appendChild(overlay);
}

// ─── Itemised bill editor (shared by the UPI modal and the edit-transaction modal) ──
// Renders dynamic "product / qty / unit / unit price" rows with add/remove and a
// live Subtotal / Discount / Tax / Grand Total box. Returns an API object so the
// caller can read the current items + computed total (e.g. to auto-fill Amount).
function buildItemsEditor(initialItems, onTotalChange){
  const wrap=h('div',{});
  const rowsEl=h('div',{class:'item-rows'});
  const totalsEl=h('div',{class:'item-totals'});
  let discount=0, tax=0;
  let rows=[]; // [{name,qty,unit,unitPrice, el, totalEl}]

  function recalc(){
    let subtotal=0;
    rows.forEach(r=>{
      const q=parseFloat(r.qtyInp.value)||0, p=parseFloat(r.priceInp.value)||0;
      const t=q*p; subtotal+=t;
      r.totalEl.textContent=fmt(t);
    });
    const grand=Math.max(0, subtotal-discount+tax);
    totalsEl.innerHTML='';
    totalsEl.appendChild(h('div',{class:'itr'},h('span',{},'Subtotal'),h('span',{},fmt(subtotal))));
    if(discount>0) totalsEl.appendChild(h('div',{class:'itr'},h('span',{},'Discount'),h('span',{},'-'+fmt(discount))));
    if(tax>0) totalsEl.appendChild(h('div',{class:'itr'},h('span',{},'Tax'),h('span',{},'+'+fmt(tax))));
    totalsEl.appendChild(h('div',{class:'itr grand'},h('span',{},'Grand Total'),h('span',{},fmt(grand))));
    if(typeof onTotalChange==='function') onTotalChange(grand);
  }

  function addRow(item){
    item=item||{name:'',qty:1,unit:'',unitPrice:''};
    const nameInp=h('input',{class:'it-name',placeholder:'Item name',value:item.name||''});
    const qtyInp=h('input',{class:'it-qty',type:'number',min:'0',step:'any',placeholder:'Qty',value:item.qty||''});
    const unitInp=h('input',{class:'it-unit',placeholder:'Unit',value:item.unit||''});
    const priceInp=h('input',{class:'it-price',type:'number',min:'0',step:'0.01',placeholder:'Price',value:item.unitPrice||''});
    const totalEl=h('div',{class:'it-total'},'₹0.00');
    const rmBtn=h('button',{type:'button',class:'it-rm'},h('i',{class:'fas fa-times'}));
    const row=h('div',{class:'item-row'},nameInp,qtyInp,unitInp,priceInp,totalEl,rmBtn);
    [qtyInp,priceInp].forEach(i=>i.addEventListener('input',recalc));
    rmBtn.addEventListener('click',()=>{
      rows=rows.filter(r=>r.el!==row);
      row.remove();
      recalc();
    });
    rowsEl.appendChild(row);
    rows.push({nameInp,qtyInp,unitInp,priceInp,totalEl,el:row});
  }

  const addBtn=h('button',{type:'button',class:'item-add-btn'},h('i',{class:'fas fa-plus'}),' Add Item');
  addBtn.addEventListener('click',()=>addRow());

  const discFg=h('div',{class:'fg',style:'display:flex;gap:10px'});
  const discInp=h('input',{class:'inp inp-bare',type:'number',min:'0',step:'0.01',placeholder:'Discount (optional)'});
  const taxInp=h('input',{class:'inp inp-bare',type:'number',min:'0',step:'0.01',placeholder:'Tax (optional)'});
  discInp.addEventListener('input',()=>{ discount=parseFloat(discInp.value)||0; recalc(); });
  taxInp.addEventListener('input',()=>{ tax=parseFloat(taxInp.value)||0; recalc(); });
  discFg.appendChild(discInp); discFg.appendChild(taxInp);

  wrap.appendChild(rowsEl);
  wrap.appendChild(addBtn);
  wrap.appendChild(discFg);
  wrap.appendChild(totalsEl);

  (initialItems&&initialItems.length?initialItems:[null]).forEach(it=>{ if(it) addRow(it); });
  if(!initialItems||!initialItems.length) addRow();
  recalc();

  return {
    el: wrap,
    getItems(){
      return rows.map(r=>({
        name:r.nameInp.value.trim(),
        qty:parseFloat(r.qtyInp.value)||0,
        unit:r.unitInp.value.trim(),
        unitPrice:parseFloat(r.priceInp.value)||0,
      })).filter(it=>it.name && it.qty>0 && it.unitPrice>=0);
    },
    getDiscount(){ return discount; },
    getTax(){ return tax; },
  };
}

// ─── UPI "Scan & Pay" modal ─────────────────────────────────────────────────
// Deep-links to a UPI app when possible; the app can NEVER know whether the
// payment actually succeeded (no merchant SDK/webhook is available from a plain
// web page), so a transaction is only ever recorded when the user explicitly
// taps "Record Transaction" — nothing is auto-confirmed.
function buildUpiModal(){
  const overlay=h('div',{class:'modal-overlay'});
  const sheet=h('div',{class:'modal-sheet'});
  sheet.appendChild(h('div',{class:'modal-handle'}));
  const titleEl=h('div',{class:'modal-title'},'📷 Scan & Pay');
  sheet.appendChild(titleEl);
  const body=h('div',{});
  sheet.appendChild(body);
  overlay.appendChild(sheet);
  overlay.addEventListener('click',e=>{ if(e.target===overlay) overlay.remove(); });
  document.body.appendChild(overlay);

  let method=null;

  function showMethodStep(){
    titleEl.textContent='📷 Scan & Pay';
    body.innerHTML='';
    const list=h('div',{class:'upi-methods'});
    UPI_METHODS.forEach(m=>{
      const row=h('div',{class:'upi-method'},
        h('div',{class:'umi'},m.icon),
        h('div',{style:'flex:1;min-width:0'},h('div',{class:'umb'},m.label),h('div',{class:'ums'},m.sub))
      );
      row.addEventListener('click',()=>{ method=m; showPayStep(); });
      list.appendChild(row);
    });
    body.appendChild(list);
    const cancelBtn=h('button',{class:'btn',style:'background:var(--p5);color:var(--mid)'},h('i',{class:'fas fa-times'}),' Cancel');
    cancelBtn.addEventListener('click',()=>overlay.remove());
    body.appendChild(cancelBtn);
  }

  async function showPayStep(){
    titleEl.textContent=method.icon+' '+method.label;
    body.innerHTML='';
    body.appendChild(h('div',{class:'upi-fallback'},h('i',{class:'fas fa-circle-info',style:'margin-top:2px'}),h('div',{},'Tapping "Open '+method.label+'" will try to launch your UPI app to complete the payment. This app cannot detect whether that payment succeeds — please open your UPI application and complete the payment, then come back and confirm below.')));

    let payeeName='',upiId='',amount='',category='',notes='',txnRef='',status='completed';
    const now=new Date();
    let dateV=toDay();
    let timeV=pad2(now.getHours())+':'+pad2(now.getMinutes());
    let useItems=false, itemsEditor=null;

    const errD=h('div',{}); body.appendChild(errD);

    const payeeInp=h('input',{class:'inp',placeholder:'e.g. ABC Vegetable Shop'});
    payeeInp.addEventListener('input',e=>payeeName=e.target.value);
    body.appendChild(h('div',{class:'fg'},h('label',{},'Paid To'),h('div',{class:'iw'},h('i',{class:'fas fa-store ic'}),payeeInp)));

    const upiInp=h('input',{class:'inp',placeholder:'name@bank (optional)'});
    upiInp.addEventListener('input',e=>upiId=e.target.value);
    body.appendChild(h('div',{class:'fg'},h('label',{},'UPI ID (optional)'),h('div',{class:'iw'},h('i',{class:'fas fa-at ic'}),upiInp)));

    const amtInp=h('input',{class:'inp',type:'number',min:'0.01',step:'0.01',placeholder:'0.00'});
    amtInp.addEventListener('input',e=>amount=e.target.value);
    body.appendChild(h('div',{class:'fg'},h('label',{},'Amount (₹)'),h('div',{class:'iw'},h('i',{class:'fas fa-indian-rupee-sign ic'}),amtInp)));

    const catSel=h('select',{class:'inp inp-bare'});
    const cats=await loadExpenseCats();
    cats.forEach(c=>catSel.appendChild(h('option',{value:c.name},c.name)));
    catSel.addEventListener('change',e=>category=e.target.value);
    category=cats.length?cats[0].name:'';
    const addCatBtn=h('button',{type:'button',class:'btn btn-xs clear-ui',style:'margin-top:6px'},h('i',{class:'fas fa-plus'}),' Add Category');
    addCatBtn.addEventListener('click',async ()=>{
      const name=prompt('New category name:');
      if(!name) return;
      try{
        await api('api/categories.php',{method:'POST',body:JSON.stringify({action:'add',name,type:'expense'})});
        await loadExpenseCats(true);
        catSel.innerHTML='';
        state.expenseCats.forEach(c=>catSel.appendChild(h('option',{value:c.name},c.name)));
        catSel.value=name; category=name;
      }catch(err){ toast(err.message); }
    });
    body.appendChild(h('div',{class:'fg'},h('label',{},'Category'),h('div',{class:'iw'},h('i',{class:'fas fa-tag ic'}),catSel),addCatBtn));

    const dtRow=h('div',{style:'display:flex;gap:10px'});
    const dateInp=h('input',{class:'inp inp-bare',type:'date',value:dateV});
    dateInp.addEventListener('change',e=>dateV=e.target.value);
    const timeInp=h('input',{class:'inp inp-bare',type:'time',value:timeV});
    timeInp.addEventListener('change',e=>timeV=e.target.value);
    dtRow.appendChild(dateInp); dtRow.appendChild(timeInp);
    body.appendChild(h('div',{class:'fg'},h('label',{},'Date & Time'),dtRow));

    const notesInp=h('textarea',{class:'inp',placeholder:'Add a note...'});
    notesInp.addEventListener('input',e=>notes=e.target.value);
    body.appendChild(h('div',{class:'fg'},h('label',{},'Notes (optional)'),notesInp));

    const refInp=h('input',{class:'inp',placeholder:'UTR / reference (optional)'});
    refInp.addEventListener('input',e=>txnRef=e.target.value);
    body.appendChild(h('div',{class:'fg'},h('label',{},'Transaction Reference (optional)'),refInp));

    const statFg=h('div',{class:'fg'});
    statFg.appendChild(h('label',{},'Status'));
    const statChips=h('div',{class:'chips'});
    STATUSES.forEach(s=>{
      const chip=h('div',{class:'chip'+(s===status?' sel':'')},s.charAt(0).toUpperCase()+s.slice(1));
      chip.addEventListener('click',()=>{ status=s; statChips.querySelectorAll('.chip').forEach(c=>c.classList.remove('sel')); chip.classList.add('sel'); });
      statChips.appendChild(chip);
    });
    statFg.appendChild(statChips); body.appendChild(statFg);

    const itemsToggle=h('button',{type:'button',class:'btn',style:'background:var(--p5);color:var(--p1)'},h('i',{class:'fas fa-list'}),' Add Itemised Bill');
    const itemsHost=h('div',{style:'display:none'});
    itemsToggle.addEventListener('click',()=>{
      useItems=!useItems;
      if(useItems){
        itemsHost.style.display='';
        itemsToggle.innerHTML=''; itemsToggle.appendChild(h('i',{class:'fas fa-minus'})); itemsToggle.append(' Remove Itemised Bill');
        if(!itemsEditor){
          itemsEditor=buildItemsEditor(null, grand=>{ amtInp.value=grand.toFixed(2); amount=amtInp.value; amtInp.disabled=true; });
          itemsHost.appendChild(itemsEditor.el);
        }
      } else {
        itemsHost.style.display='none';
        itemsToggle.innerHTML=''; itemsToggle.appendChild(h('i',{class:'fas fa-list'})); itemsToggle.append(' Add Itemised Bill');
        amtInp.disabled=false;
      }
    });
    body.appendChild(itemsToggle);
    body.appendChild(itemsHost);

    const openAppBtn=h('button',{class:'btn btn-p'},h('i',{class:'fas fa-external-link-alt'}),' Open '+method.label);
    openAppBtn.addEventListener('click',()=>{
      const amt=parseFloat(amount)||0;
      const params=new URLSearchParams();
      if(upiId) params.set('pa',upiId);
      if(payeeName) params.set('pn',payeeName);
      if(amt>0) params.set('am',amt.toFixed(2));
      params.set('cu','INR');
      if(txnRef) params.set('tr',txnRef);
      const link='upi://pay?'+params.toString();
      window.location.href=link;
      toast('If your UPI app did not open, please launch it manually.');
    });
    body.appendChild(openAppBtn);

    const recordBtn=h('button',{class:'btn btn-d'},h('i',{class:'fas fa-check-circle'}),' Record Transaction');
    recordBtn.addEventListener('click',async ()=>{
      const a=parseFloat(amount);
      const items=useItems&&itemsEditor?itemsEditor.getItems():[];
      if(!useItems && (!amount||isNaN(a)||a<=0)){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please enter a valid amount greater than 0.')); return; }
      if(useItems && !items.length){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please add at least one item.')); return; }
      if(!payeeName){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please enter who you paid.')); return; }
      if(!category){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please select a category.')); return; }
      recordBtn.disabled=true;
      try{
        await api('api/transactions.php',{method:'POST',body:JSON.stringify({
          type:'expense', amount:a||0, category, notes, date:dateV,
          payeeName, upiId, paymentMethod:'upi', time:timeV, txnRef, status,
          items: useItems?items:undefined,
          discount: useItems&&itemsEditor?itemsEditor.getDiscount():0,
          tax: useItems&&itemsEditor?itemsEditor.getTax():0,
        })});
        overlay.remove();
        await refreshAll();
        toast('Transaction recorded!');
        render();
      }catch(err){
        errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' '+err.message));
        recordBtn.disabled=false;
      }
    });
    body.appendChild(recordBtn);

    const backBtn=h('button',{class:'btn',style:'background:var(--p5);color:var(--mid)'},h('i',{class:'fas fa-chevron-left'}),' Back');
    backBtn.addEventListener('click',showMethodStep);
    body.appendChild(backBtn);
  }

  showMethodStep();
}

// ─── DOM helper ─────────────────────────────────────────────────────────────
function h(tag, attrs={}, ...children){
  const el = document.createElement(tag);
  entries(attrs).forEach(([k,v])=>{
    if(k==='class') el.className=v;
    else if(k.startsWith('on')) el.addEventListener(k.slice(2).toLowerCase(),v);
    else el.setAttribute(k,v);
  });
  children.forEach(c=>{ if(c==null||c===false) return; el.append(typeof c==='string'||typeof c==='number'?document.createTextNode(c):c); });
  return el;
}

function render(){ document.getElementById('root').innerHTML=''; document.getElementById('root').appendChild(buildApp()); }

function buildApp(){
  if(!state.user) return authPage==='login'?buildLogin():buildRegister();
  const wrap = h('div',{class:'app'});
  if(page==='dashboard') wrap.appendChild(buildDashboard());
  else if(page==='add-expense') wrap.appendChild(buildAddTx('expense'));
  else if(page==='add-income') wrap.appendChild(buildAddTx('income'));
  else if(page==='expense') wrap.appendChild(buildTxList('expense'));
  else if(page==='income') wrap.appendChild(buildTxList('income'));
  else if(page==='stats') wrap.appendChild(buildStats());
  else if(page==='profile') wrap.appendChild(buildProfile());
  else if(page==='settings') wrap.appendChild(buildSettings());
  else if(page==='tx-history') wrap.appendChild(buildTransactionHistory());
  else if(page==='events') wrap.appendChild(buildEvents());
  else if(page==='calc') wrap.appendChild(buildCalcModule());
  if(page==='dashboard') wrap.appendChild(buildAiFab());
  wrap.appendChild(buildNav());
  return wrap;
}

// ─── Dark / light theme toggle ───────────────────────────────────────────────
const THEME_KEY='mw-theme';
const currentTheme=()=> (localStorage.getItem(THEME_KEY)==='dark'?'dark':'light');
function applyTheme(){
  const t=currentTheme();
  document.documentElement.setAttribute('data-theme',t);
  document.documentElement.style.colorScheme=t;
  document.querySelectorAll('.mw-theme-btn').forEach(b=>{
    const dark=t==='dark';
    b.classList.toggle('dark-active',dark);
    b.setAttribute('aria-checked',String(dark));
    b.setAttribute('aria-label',dark?'Switch to light mode':'Switch to dark mode');
  });
  document.querySelectorAll('.dm-sub').forEach(el=>{ el.textContent= t==='dark'?'Dark theme is on':'Switch to a dark theme'; });
  return t;
}
function toggleTheme(){
  try{ localStorage.setItem(THEME_KEY, currentTheme()==='dark'?'light':'dark'); }catch(e){}
  applyTheme();
}
function buildThemeToggle(){
  const dark=currentTheme()==='dark';
  const btn=h('button',{type:'button',class:'mw-theme-btn',role:'switch','aria-checked':dark,'aria-label':dark?'Switch to light mode':'Switch to dark mode',title:dark?'Light mode':'Dark mode'});
  btn.appendChild(h('i',{class:'fas fa-sun mw-theme-sun mw-theme-ic'}));
  btn.appendChild(h('i',{class:'fas fa-moon mw-theme-moon mw-theme-ic'}));
  btn.appendChild(h('span',{class:'mw-theme-knob'}));
  if(dark) btn.classList.add('dark-active');
  btn.addEventListener('click',toggleTheme);
  btn.addEventListener('keydown',e=>{ if(e.key===' '||e.key==='Enter'){ e.preventDefault(); toggleTheme(); } });
  return btn;
}
function buildThemeSettingsRow(){
  const row=h('div',{class:'settings-row',style:'cursor:default'});
  row.appendChild(h('div',{class:'settings-row-icon sri-purple'},h('i',{class:'fas fa-moon'})));
  const body=h('div',{class:'settings-row-body'});
  body.appendChild(h('div',{class:'settings-row-title'},'Dark Mode'));
  body.appendChild(h('div',{class:'settings-row-sub dm-sub'},currentTheme()==='dark'?'Dark theme is on':'Switch to a dark theme'));
  row.appendChild(body);
  row.appendChild(buildThemeToggle());
  return row;
}

function buildAiFab(){
  const fab=h('button',{type:'button',class:'ai-fab'},h('i',{class:'fas fa-robot'}),h('span',{},'Ask AI'));
  fab.addEventListener('click',aiOpenOverlay);
  return fab;
}

function buildLogo(){
  return h('div',{class:'auth-logo'},
    h('div',{class:'logo-icon'},h('img',{class:'logo-img',src:'Mlogo/MoneywiseLOGO.png?v=<?php echo $logov; ?>',alt:'MoneyWise'})),
    h('h1',{},'Money',h('span',{},'Wise'))
  );
}

function buildLogin(){
  let email='',pass='',showP=false;
  const wrap = h('div',{class:'auth-wrap'});
  const card = h('div',{class:'auth-card fade'});
  card.appendChild(buildLogo());
  card.appendChild(h('div',{class:'auth-title'},h('h2',{},'Welcome Back!'),h('p',{},'Sign in to your account')));
  const errDiv = h('div',{});
  card.appendChild(errDiv);
  const emailInp = h('input',{class:'inp',type:'email',placeholder:'you@example.com'});
  emailInp.addEventListener('input',e=>email=e.target.value);
  card.appendChild(h('div',{class:'fg'},h('label',{},'Email Address'),h('div',{class:'iw'},h('i',{class:'fas fa-envelope ic'}),emailInp)));
  const passInp = h('input',{class:'inp',type:'password',placeholder:'Your password'});
  passInp.addEventListener('input',e=>pass=e.target.value);
  const eyeBtn = h('button',{type:'button',style:'position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--p3);cursor:pointer;font-size:14px'},h('i',{class:'fas fa-eye'}));
  eyeBtn.addEventListener('click',()=>{ showP=!showP; passInp.type=showP?'text':'password'; eyeBtn.querySelector('i').className='fas fa-eye'+(showP?'-slash':''); });
  card.appendChild(h('div',{class:'fg'},h('label',{},'Password'),h('div',{class:'iw',style:'position:relative'},h('i',{class:'fas fa-lock ic'}),passInp,eyeBtn)));
  const btn = h('button',{class:'btn btn-p'},h('i',{class:'fas fa-sign-in-alt'}),' Sign In');
  btn.addEventListener('click',async ()=>{
    if(!email||!pass){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please enter your email and password.')); return; }
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in...';
    try {
      const d = await api('api/auth.php', { method:'POST', body: JSON.stringify({ action:'login', email, password:pass }) });
      state.user = d.user;
      await refreshAll();
      setPage('dashboard'); render();
    } catch (err) {
      errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' '+err.message));
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Sign In';
    }
  });
  card.appendChild(btn);
  const foot = h('div',{class:'auth-footer'},"Don't have an account? ");
  const link = h('a',{},'Sign Up'); link.addEventListener('click',()=>{ authPage='register'; render(); });
  foot.appendChild(link); card.appendChild(foot);
  wrap.appendChild(card); return wrap;
}

function buildRegister(){
  let name='',email='',pass='',confirm='';
  const wrap = h('div',{class:'auth-wrap'});
  const card = h('div',{class:'auth-card fade'});
  card.appendChild(buildLogo());
  card.appendChild(h('div',{class:'auth-title'},h('h2',{},'Create Account'),h('p',{},'Start managing your finances today')));
  const errDiv=h('div',{}); card.appendChild(errDiv);
  const mkFg=(lbl,typ,ph,cb)=>{
    const inp=h('input',{class:'inp',type:typ,placeholder:ph});
    inp.addEventListener('input',e=>cb(e.target.value));
    return h('div',{class:'fg'},h('label',{},lbl),h('div',{class:'iw'},h('i',{class:`fas fa-user ic`}),inp));
  };
  card.appendChild(h('div',{class:'fg'},h('label',{},'Full Name'),h('div',{class:'iw'},h('i',{class:'fas fa-user ic'}),Object.assign(h('input',{class:'inp',placeholder:'John Doe'}),{oninput:e=>name=e.target.value}))));
  card.appendChild(mkFg('Email Address','email','you@example.com',v=>email=v));
  card.appendChild(mkFg('Password','password','Min. 8 characters',v=>pass=v));
  card.appendChild(mkFg('Confirm Password','password','Repeat password',v=>confirm=v));
  const btn=h('button',{class:'btn btn-p'},h('i',{class:'fas fa-user-plus'}),' Create Account');
  btn.addEventListener('click',async ()=>{
    if(!name||!email||!pass||!confirm){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' All fields are required.')); return; }
    if(pass.length<8){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Password must be at least 8 characters.')); return; }
    if(pass!==confirm){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Passwords do not match.')); return; }
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating account...';
    try {
      const d = await api('api/auth.php', { method:'POST', body: JSON.stringify({ action:'register', name, email, password:pass }) });
      errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-s'},h('i',{class:'fas fa-circle-check'}),' Account created! Please sign in.'));
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-user-plus"></i> Create Account';
      authPage='login'; render();
    } catch (err) {
      errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' '+err.message));
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-user-plus"></i> Create Account';
    }
  });
  card.appendChild(btn);
  const foot=h('div',{class:'auth-footer'},'Already have an account? ');
  const link=h('a',{},'Sign In'); link.addEventListener('click',()=>{ authPage='login'; render(); });
  foot.appendChild(link); card.appendChild(foot);
  wrap.appendChild(card); return wrap;
}

function buildDashboard(){
  const myTxs=state.txs.slice().sort((a,b)=>b.date.localeCompare(a.date));
  const inPeriod=t=>{
    if(statusYear>0&&+t.date.slice(0,4)!==statusYear) return false;
    if(statusMonth>0&&+t.date.slice(5,7)!==statusMonth) return false;
    return true;
  };
  const periodTxs=state.txs.filter(inPeriod);
  const income=periodTxs.filter(t=>t.type==='income').reduce((s,t)=>s+ (+t.amount),0);
  const expense=periodTxs.filter(t=>t.type==='expense').reduce((s,t)=>s+ (+t.amount),0);
  const bal=income-expense;
  const pg=h('div',{class:'page fade'});
  const dh=h('div',{class:'dash-hdr'});
  dh.appendChild(h('div',{class:'greeting'},greet()));
  dh.appendChild(h('div',{class:'uname'},'Hello, '+state.user.name+' 👑'));
  dh.appendChild(h('div',{class:'bal-lbl'},'Available Balance'));
  const balRow=h('div',{style:'display:flex;align-items:center;gap:12px'});
  balRow.appendChild(h('div',{class:'bal-amt'},state.dashBalanceCleared?fmt(0):((bal<0?'-':'')+fmt(bal))));
  const eraseBtn=h('button',{type:'button',class:'bal-erase',title:'Erase Balance — hides the balance until a new transaction is added (records stay in the database)',onclick:()=>{
    confirmClear('This will set your displayed Available Balance to '+fmt(0)+'. No income or expense records are deleted — the balance will reappear automatically once you add a new transaction.',async ()=>{
      try{ await persistBalanceClear(true); render(); toast('Balance cleared — it will reappear when a new transaction is added.'); }
      catch(err){ toast(err.message); }
    });
  }},h('i',{class:'fas fa-eraser'}));
  const qrBtn=h('button',{type:'button',class:'qr-btn',title:'Scan & Pay via UPI',onclick:()=>buildUpiModal()},h('i',{class:'fas fa-qrcode'}));
  balRow.appendChild(eraseBtn);
  balRow.appendChild(qrBtn);
  dh.appendChild(balRow);
  pg.appendChild(dh);
  const expOnly=myTxs.filter(t=>t.type==='expense');
  const td=toDay();
  const dToday=expOnly.filter(t=>t.date===td).reduce((s,t)=>s+t.amount,0);
  const dMonth=expOnly.filter(t=>t.date.startsWith(td.slice(0,7))).reduce((s,t)=>s+t.amount,0);
  const dYear=expOnly.filter(t=>t.date.startsWith(td.slice(0,4))).reduce((s,t)=>s+t.amount,0);
  const dg=h('div',{class:'ds-grid'});
  dg.appendChild(dsTile('Today', dToday, 'fa-calendar-day'));
  dg.appendChild(dsTile('This Month', dMonth, 'fa-calendar-alt'));
  dg.appendChild(dsTile('This Year', dYear, 'fa-calendar'));
  pg.appendChild(dg);
  const qb=h('div',{class:'qb'});
  const eb=h('button',{class:'btn btn-d'},h('i',{class:'fas fa-minus-circle'}),' Add Expense');
  eb.addEventListener('click',()=>{setPage('add-expense');render();});
  const ib=h('button',{class:'btn btn-p'},h('i',{class:'fas fa-plus-circle'}),' Add Income');
  ib.addEventListener('click',()=>{setPage('add-income');render();});
  qb.appendChild(eb); qb.appendChild(ib); pg.appendChild(qb);
  const evBtn=h('button',{type:'button',class:'event-btn'},h('i',{class:'fas fa-champagne-glasses'}),' Event Expenses');
  evBtn.addEventListener('click',async ()=>{
    eventSub='list'; eventFilters={type:'',name:'',month:'',year:''};
    setPage('events'); render();
    refreshEventsList();
  });
  pg.appendChild(evBtn);
  const tl=h('div',{class:'tx-list'});
  const shRight=h('div',{class:'sh-right'});
  const viewAllLink=h('span',{class:'view-all-link'},'View all →');
  viewAllLink.addEventListener('click',()=>{ setPage('tx-history'); render(); });
  shRight.appendChild(viewAllLink);
  shRight.appendChild(h('div',{class:'sh-sub'},fmtDate(toDay())));
  shRight.appendChild(h('button',{type:'button',class:'btn btn-xs clear-ui',title:'Hide the entries shown here (records stay in the database)',onclick:()=>{
    const ids=recent.map(t=>+t.id);
    if(!ids.length){ toast('Nothing to clear here.'); return; }
    confirmClear('Are you sure you want to clear all entries?',async ()=>{
      try{ await persistClear(ids); render(); toast('Recent transactions cleared from this view — database unchanged.'); }
      catch(err){ toast(err.message); }
    });
  }},h('i',{class:'fas fa-broom'}),' Clear'));
  pg.appendChild(h('div',{class:'sh'},h('h3',{},'Recent Transactions'),shRight));
  const today=toDay();
  const todayTxs=state.txs.filter(t=>t.date===today);
  const salTx=todayTxs.filter(t=>t.type==='income'&&t.cat==='Salary').sort((a,b)=>b.date.localeCompare(a.date)||(b.id||0)-(a.id||0))[0];
  const recent=[...todayTxs.filter(t=>!(t.type==='income'&&t.cat==='Salary')),...(salTx?[salTx]:[])].sort((a,b)=>b.date.localeCompare(a.date)||(b.id||0)-(a.id||0)).slice(0,10);
  const recentVisible=recent.filter(t=>!isTxCleared(t));
  if(!recentVisible.length){
    tl.appendChild(h('div',{class:'empty'},h('div',{class:'eic'},'📭'),h('p',{},recent.length?'Recent transactions cleared from view — records still exist in the database.':'No transactions for today.')));
  } else {
    recentVisible.forEach(tx=>{
      const ti=h('div',{class:'tx'});
      ti.appendChild(h('div',{class:`tx-ic ${tx.type}`},AICONS[tx.cat]||'📋'));
      const bd=h('div',{class:'tx-bd'});
      if(tx.payeeName){
        bd.appendChild(h('div',{class:'tx-payee'},tx.payeeName));
        bd.appendChild(h('div',{class:'tx-sub'},h('span',{},tx.cat),h('span',{},'·'),h('span',{},fmtDate(tx.date))));
      } else {
        bd.appendChild(h('div',{class:'tx-cat'},tx.cat));
        bd.appendChild(h('div',{class:'tx-dt'},fmtDate(tx.date)));
      }
      if(tx.notes) bd.appendChild(h('div',{class:'tx-nt'},tx.notes));
      ti.appendChild(bd);
      ti.appendChild(h('div',{class:`tx-am ${tx.type}`},(tx.type==='income'?'+':'-')+fmt(tx.amount)));
      tl.appendChild(ti);
    });
  }
  pg.appendChild(tl); return pg;
}

function buildAddTx(type){
  let amount='',category='',date=toDay(),notes='';
  const cats=type==='income'?ICATS:ECATS;
  const pg=h('div',{class:'page fade'});
  const phdr=h('div',{class:'page-hdr'});
  const bb=h('button',{class:'back-btn'},h('i',{class:'fas fa-chevron-left'}));
  bb.addEventListener('click',()=>{ page=type==='income'?'income':'expense'; render(); });
  phdr.appendChild(bb); phdr.appendChild(h('h2',{},'Add '+(type==='income'?'Income':'Expense')));
  pg.appendChild(phdr);
  const tog=h('div',{class:'tab-tog'});
  const expBtn=h('button',{class:type==='expense'?'act':''},'Expenses');
  expBtn.addEventListener('click',()=>{setPage('add-expense');render();});
  const incBtn=h('button',{class:type==='income'?'act':''},'Income');
  incBtn.addEventListener('click',()=>{setPage('add-income');render();});
  tog.appendChild(expBtn); tog.appendChild(incBtn); pg.appendChild(tog);
  const errDiv=h('div',{}); pg.appendChild(errDiv);
  const fc=h('div',{class:'fc'});
  const amtDisp=h('div',{class:'amt-disp'});
  const amtInp=h('input',{class:'amt-inp',type:'number',inputmode:'decimal',placeholder:'0.00',min:'0.01',step:'0.01'});
  amtInp.addEventListener('input',e=>amount=e.target.value);
  amtDisp.appendChild(h('span',{class:'amt-cur'},'₹'));
  amtDisp.appendChild(amtInp);
  amtDisp.appendChild(h('div',{class:'amt-hint'},'Enter Amount'));
  fc.appendChild(amtDisp);
  const chipsFg=h('div',{class:'fg'});
  chipsFg.appendChild(h('label',{},type==='income'?'Income Source':'Category'));
  const chipsDiv=h('div',{class:'chips'});
  entries(cats).forEach(([k,v])=>{
    const chip=h('div',{class:'chip'},v+' '+k);
    chip.addEventListener('click',()=>{
      category=k;
      chipsDiv.querySelectorAll('.chip').forEach(c=>c.classList.remove('sel'));
      chip.classList.add('sel');
    });
    chipsDiv.appendChild(chip);
  });
  chipsFg.appendChild(chipsDiv); fc.appendChild(chipsFg);
  const dateInp=h('input',{class:'inp',type:'date',value:date,required:''});
  dateInp.value=date;
  dateInp.addEventListener('change',e=>date=e.target.value);
  fc.appendChild(h('div',{class:'fg'},h('label',{},'Date'),h('div',{class:'iw'},h('i',{class:'fas fa-calendar ic'}),dateInp)));
  const notesInp=h('textarea',{class:'inp',placeholder:'Add a note...'});
  notesInp.addEventListener('input',e=>notes=e.target.value);
  fc.appendChild(h('div',{class:'fg'},h('label',{},'Notes (',h('span',{style:'color:var(--light);font-weight:400'},'optional'),')'),h('div',{class:'iw',style:'position:relative'},h('i',{class:'fas fa-note-sticky ic',style:'top:17px;transform:none'}),notesInp)));
  const sub=h('button',{class:`btn ${type==='income'?'btn-p':'btn-d'}`},h('i',{class:`fas ${type==='income'?'fa-plus-circle':'fa-minus-circle'}`}),` Add ${type==='income'?'Income':'Expense'}`);
  sub.addEventListener('click',async ()=>{
    const a=parseFloat(amount);
    if(!amount||isNaN(a)||a<=0){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please enter a valid amount greater than 0.')); return; }
    if(!category){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),` Please select a ${type==='income'?'source':'category'}.`)); return; }
    if(!date){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please select a date.')); return; }
    sub.disabled=true;
    try {
      await api('api/transactions.php', { method:'POST', body: JSON.stringify({ type, amount:a, category, notes, date }) });
      await refreshAll();
      toast((type==='income'?'Income':'Expense')+' added successfully!');
      page=type==='income'?'income':'expense'; render();
    } catch (err) {
      errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' '+err.message));
      sub.disabled=false;
    }
  });
  fc.appendChild(sub); pg.appendChild(fc); return pg;
}

// ─── EXPENSE / INCOME LIST SCREENS ────────────────────────────────────────────
function showEditTxModal(tx, onSaved){
  const overlay=h('div',{class:'modal-overlay'});
  const sheet=h('div',{class:'modal-sheet'});
  sheet.appendChild(h('div',{class:'modal-handle'}));
  sheet.appendChild(h('div',{class:'modal-title'},'✏️ Edit '+(tx.type==='income'?'Income':'Expense')));
  const errD=h('div',{}); sheet.appendChild(errD);
  let amount=String(tx.amount), category=tx.category, date=tx.date||toDay(), notes=tx.notes||'';
  let payeeName=tx.payeeName||'', upiId=tx.upiId||'', paymentMethod=tx.paymentMethod||'cash';
  let txnRef=tx.txnRef||'', status=tx.status||'completed', ttime=tx.time||'';
  const hasItems=Array.isArray(tx.items)&&tx.items.length>0;
  let useItems=hasItems, itemsEditor=null;
  const cats=tx.type==='income'?ICATS:ECATS;

  const amtInp=h('input',{class:'inp inp-bare',type:'number',inputmode:'decimal',min:'0.01',step:'0.01',value:amount,placeholder:'0.00'});
  amtInp.addEventListener('input',e=>amount=e.target.value);
  sheet.appendChild(h('div',{class:'fg'},h('label',{},'Amount (₹)'),h('div',{class:'iw'},h('i',{class:'fas fa-indian-rupee-sign ic'}),amtInp)));

  const chipsDiv=h('div',{class:'chips'});
  entries(cats).forEach(([k,v])=>{
    const chip=h('div',{class:'chip'+(k===category?' sel':'')},v+' '+k);
    chip.addEventListener('click',()=>{
      category=k;
      chipsDiv.querySelectorAll('.chip').forEach(c=>c.classList.remove('sel'));
      chip.classList.add('sel');
    });
    chipsDiv.appendChild(chip);
  });
  sheet.appendChild(h('div',{class:'fg'},h('label',{},tx.type==='income'?'Income Source':'Category'),chipsDiv));

  const dateInp=h('input',{class:'inp inp-bare',type:'date',value:date,required:''});
  dateInp.value=date;
  dateInp.addEventListener('change',e=>date=e.target.value);
  sheet.appendChild(h('div',{class:'fg'},h('label',{},'Date'),h('div',{class:'iw'},h('i',{class:'fas fa-calendar ic'}),dateInp)));

  const notesInp=h('textarea',{class:'inp',placeholder:'Add a note...'});
  notesInp.value=notes;
  notesInp.addEventListener('input',e=>notes=e.target.value);
  sheet.appendChild(h('div',{class:'fg'},h('label',{},'Notes (',h('span',{style:'color:var(--light);font-weight:400'},'optional'),')'),h('div',{class:'iw',style:'position:relative'},h('i',{class:'fas fa-note-sticky ic',style:'top:17px;transform:none'}),notesInp)));

  // ── Optional payment details (payee / UPI / method / status / items) ──
  const payDetailsHost=h('div',{style:hasItems||tx.payeeName?'':'display:none'});
  if(tx.type==='expense'){
    const payeeInp=h('input',{class:'inp',value:payeeName,placeholder:'Payee / shop name'});
    payeeInp.addEventListener('input',e=>payeeName=e.target.value);
    payDetailsHost.appendChild(h('div',{class:'fg'},h('label',{},'Paid To (optional)'),payeeInp));

    const upiInp=h('input',{class:'inp',value:upiId,placeholder:'name@bank (optional)'});
    upiInp.addEventListener('input',e=>upiId=e.target.value);
    payDetailsHost.appendChild(h('div',{class:'fg'},h('label',{},'UPI ID (optional)'),upiInp));

    const methFg=h('div',{class:'fg'}); methFg.appendChild(h('label',{},'Payment Method'));
    const methChips=h('div',{class:'chips'});
    PAY_METHODS.forEach(m=>{
      const chip=h('div',{class:'chip'+(m===paymentMethod?' sel':'')},m.charAt(0).toUpperCase()+m.slice(1));
      chip.addEventListener('click',()=>{ paymentMethod=m; methChips.querySelectorAll('.chip').forEach(c=>c.classList.remove('sel')); chip.classList.add('sel'); });
      methChips.appendChild(chip);
    });
    methFg.appendChild(methChips); payDetailsHost.appendChild(methFg);

    const statFg=h('div',{class:'fg'}); statFg.appendChild(h('label',{},'Status'));
    const statChips=h('div',{class:'chips'});
    STATUSES.forEach(s=>{
      const chip=h('div',{class:'chip'+(s===status?' sel':'')},s.charAt(0).toUpperCase()+s.slice(1));
      chip.addEventListener('click',()=>{ status=s; statChips.querySelectorAll('.chip').forEach(c=>c.classList.remove('sel')); chip.classList.add('sel'); });
      statChips.appendChild(chip);
    });
    statFg.appendChild(statChips); payDetailsHost.appendChild(statFg);

    const refInp=h('input',{class:'inp',value:txnRef,placeholder:'UTR / reference (optional)'});
    refInp.addEventListener('input',e=>txnRef=e.target.value);
    payDetailsHost.appendChild(h('div',{class:'fg'},h('label',{},'Transaction Reference (optional)'),refInp));

    const itemsHost=h('div',{style:useItems?'':'display:none'});
    function ensureItemsEditor(){
      if(!itemsEditor){
        itemsEditor=buildItemsEditor(hasItems?tx.items.map(it=>({name:it.name,qty:it.qty,unit:it.unit,unitPrice:it.unitPrice})):null, grand=>{ amtInp.value=grand.toFixed(2); amount=amtInp.value; amtInp.disabled=true; });
        itemsHost.appendChild(itemsEditor.el);
      }
    }
    if(useItems) ensureItemsEditor();
    const itemsToggle=h('button',{type:'button',class:'btn',style:'background:var(--p5);color:var(--p1)'},
      h('i',{class:`fas ${useItems?'fa-minus':'fa-list'}`}),' '+(useItems?'Remove Itemised Bill':'Add Itemised Bill'));
    itemsToggle.addEventListener('click',()=>{
      useItems=!useItems;
      itemsHost.style.display=useItems?'':'none';
      itemsToggle.innerHTML=''; itemsToggle.appendChild(h('i',{class:`fas ${useItems?'fa-minus':'fa-list'}`})); itemsToggle.append(' '+(useItems?'Remove Itemised Bill':'Add Itemised Bill'));
      if(useItems) ensureItemsEditor(); else amtInp.disabled=false;
    });
    payDetailsHost.appendChild(itemsToggle);
    payDetailsHost.appendChild(itemsHost);
  }
  const payToggle=h('button',{type:'button',class:'btn btn-xs clear-ui',style:'margin-bottom:10px'},h('i',{class:'fas fa-sliders'}),' Payment Details (optional)');
  if(tx.type==='expense'){
    payToggle.addEventListener('click',()=>{ payDetailsHost.style.display=payDetailsHost.style.display==='none'?'':'none'; });
    sheet.appendChild(payToggle);
    sheet.appendChild(payDetailsHost);
  }

  const saveBtn=h('button',{class:'btn btn-p'},h('i',{class:'fas fa-save'}),' Save Changes');
  saveBtn.addEventListener('click',async ()=>{
    const items=useItems&&itemsEditor?itemsEditor.getItems():[];
    const a=parseFloat(amount);
    if(!useItems && (!amount||isNaN(a)||a<=0)){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please enter a valid amount greater than 0.')); return; }
    if(useItems && !items.length){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please add at least one item.')); return; }
    if(!category){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),` Please select a ${tx.type==='income'?'source':'category'}.`)); return; }
    if(!date){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please select a date.')); return; }
    saveBtn.disabled=true;
    try {
      await api('api/transactions.php', { method:'PUT', body: JSON.stringify({
        id:tx.id, type:tx.type, amount:a||0, category, notes, date,
        payeeName, upiId, paymentMethod, time:ttime, txnRef, status,
        items: useItems?items:undefined,
        discount: useItems&&itemsEditor?itemsEditor.getDiscount():0,
        tax: useItems&&itemsEditor?itemsEditor.getTax():0,
      }) });
      overlay.remove();
      await refreshAll();
      toast('Updated successfully!');
      if(typeof onSaved==='function') onSaved();
    } catch (err) {
      errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' '+err.message));
      saveBtn.disabled=false;
    }
  });
  const cancelBtn=h('button',{class:'btn',style:'background:var(--p5);color:var(--mid);margin-top:8px'},h('i',{class:'fas fa-times'}),' Cancel');
  cancelBtn.addEventListener('click',()=>overlay.remove());
  sheet.appendChild(saveBtn); sheet.appendChild(cancelBtn);
  overlay.appendChild(sheet);
  overlay.addEventListener('click',e=>{ if(e.target===overlay) overlay.remove(); });
  document.body.appendChild(overlay);
}

function buildTxList(type){
  const now=new Date();
  let mode='all', selMonth=now.getMonth()+1, selYear=now.getFullYear();
  let currentTxs=[];

  const pg=h('div',{class:'page fade'});
  const phdr=h('div',{class:'page-hdr'});
  phdr.appendChild(h('h2',{},type==='expense'?'💸 Expenses':'💵 Income'));
  pg.appendChild(phdr);

  const addBtn=h('button',{class:`btn ${type==='income'?'btn-p':'btn-d'}`},h('i',{class:`fas ${type==='income'?'fa-plus-circle':'fa-minus-circle'}`}),` Add ${type==='income'?'Income':'Expense'}`);
  addBtn.addEventListener('click',()=>{ page=type==='income'?'add-income':'add-expense'; render(); });
  const clearBtn=h('button',{type:'button',class:'btn btn-xs clear-ui',title:'Hide the entries shown here (records stay in the database)',onclick:()=>{
    const ids=currentTxs.map(t=>+t.id);
    if(!ids.length){ toast('Nothing to clear here.'); return; }
    confirmClear('Are you sure you want to clear all entries?',async ()=>{
      try{ await persistClear(ids); load(); toast('Cleared current view — database unchanged.'); }
      catch(err){ toast(err.message); }
    });
  }},h('i',{class:'fas fa-broom'}),' Clear View');
  const btnRow=h('div',{class:'tx-clear-row'});
  btnRow.appendChild(addBtn); btnRow.appendChild(clearBtn);
  pg.appendChild(btnRow);

  const tog=h('div',{class:'tab-tog'});
  const allBtn=h('button',{class:'act'},'All');
  const monBtn=h('button',{},'Monthly');
  const yrBtn=h('button',{},'Yearly');
  tog.appendChild(allBtn); tog.appendChild(monBtn); tog.appendChild(yrBtn);
  pg.appendChild(tog);

  const frow=h('div',{class:'ev-filters'});
  const monthSel=h('select',{});
  MONTHS.forEach((m,i)=>{ const o=h('option',{value:i+1},m); if(i+1===selMonth) o.selected=true; monthSel.appendChild(o); });
  const yearSel=h('select',{});
  frow.appendChild(monthSel); frow.appendChild(yearSel);
  frow.style.display='none';
  pg.appendChild(frow);

  const content=h('div',{});
  pg.appendChild(content);

  function rebuildYears(years){
    yearSel.innerHTML='';
    const list=[...new Set([...(years||[]), now.getFullYear()])].sort((a,b)=>b-a);
    if(list.length && list.indexOf(selYear)<0) selYear=list[0];
    list.forEach(y=>{ const o=h('option',{value:y},y); if(y===selYear) o.selected=true; yearSel.appendChild(o); });
  }

  function periodLabel(){
    if(mode==='monthly') return MONTHS[selMonth-1]+' '+selYear;
    if(mode==='yearly') return 'Year '+selYear;
    return 'All Records';
  }

  async function load(){
    content.innerHTML='';
    content.appendChild(h('div',{class:'empty',style:'padding:28px'},h('div',{class:'eic'},'⏳'),h('p',{},'Loading...')));
    const q=new URLSearchParams({ type });
    if(mode==='monthly'){ q.set('mode','monthly'); q.set('month',selMonth); q.set('year',selYear); }
    else if(mode==='yearly'){ q.set('mode','yearly'); q.set('year',selYear); }
    let d;
    try {
      d = await api('api/transactions.php?'+q.toString());
    } catch (err) {
      content.innerHTML='';
      content.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' '+err.message));
      return;
    }
    content.innerHTML='';
    rebuildYears(d.availableYears);
    const txs=d.transactions||[];
    currentTxs=txs;

    const vis=txs.filter(t=>!isTxCleared(t));
    const vTotal=vis.reduce((x,t)=>x+(+t.amount),0);
    const vCount=vis.length;
    const vAvg=vCount?vTotal/vCount:0;
    const vCats={};
    vis.forEach(t=>{ vCats[t.cat]=(vCats[t.cat]||0)+(+t.amount); });

    const grid=h('div',{class:'mstat-grid'});
    grid.appendChild(mstat('Total '+(type==='expense'?'Expense':'Income'), fmt(vTotal), 'fa-indian-rupee-sign', 'rd'));
    grid.appendChild(mstat('Number of '+(type==='expense'?'Expenses':'Records'), vCount+(vCount!==1?'':' record'), 'fa-receipt'));
    grid.appendChild(mstat('Average', fmt(vAvg), 'fa-chart-line'));
    grid.appendChild(mstat('Period', periodLabel(), 'fa-calendar'));
    content.appendChild(grid);

    const catEntries=entries(vCats);
    if(catEntries.length){
      content.appendChild(sectionTitle('Category-wise Summary'));
      content.appendChild(barList(vCats, vTotal, k=>AICONS[k]||'📋'));
    }

    content.appendChild(sectionTitle(type==='expense'?'Expense Records':'Income Records'));
    if(!vis.length){
      content.appendChild(h('div',{class:'empty'},h('div',{class:'eic'},'📭'),h('p',{},txs.length?'Records cleared from this view — records still exist in the database.':'No '+(type==='expense'?'expenses':'income records')+' found for this period.')));
      return;
    }
    const days={};
    vis.forEach(t=>{ (days[t.date]=days[t.date]||[]).push(t); });
    Object.keys(days).forEach(date=>{
      const dayWrap=h('div',{});
      dayWrap.appendChild(h('div',{class:'day-hdr'},fmtDate(date)));
      const dl=h('div',{class:'tx-list'});
      days[date].forEach(tx=>{
        const ti=h('div',{class:'tx'});
        ti.appendChild(h('div',{class:`tx-ic ${tx.type}`},AICONS[tx.cat]||'📋'));
        const bd=h('div',{class:'tx-bd'});
        bd.appendChild(h('div',{class:'tx-cat'},tx.cat));
        bd.appendChild(h('div',{class:'tx-dt'},fmtDate(tx.date)));
        if(tx.notes) bd.appendChild(h('div',{class:'tx-nt'},tx.notes));
        ti.appendChild(bd);
        ti.appendChild(h('div',{class:`tx-am ${tx.type}`},(tx.type==='income'?'+':'-')+fmt(tx.amount)));
        const acts=h('div',{class:'tx-acts'});
        const editBtn=h('button',{type:'button',class:'tx-act',title:'Edit'},h('i',{class:'fas fa-pen'}));
        editBtn.addEventListener('click',()=>{ showEditTxModal(tx, load); });
        const delBtn=h('button',{type:'button',class:'tx-act del',title:'Delete'},h('i',{class:'fas fa-trash'}));
        delBtn.addEventListener('click',async ()=>{
          delBtn.disabled=true;
          try {
            await api('api/transactions.php?id='+tx.id, { method:'DELETE' });
            await refreshAll();
            toast('Deleted!');
            load();
          } catch (err) { toast(err.message); delBtn.disabled=false; }
        });
        acts.appendChild(editBtn); acts.appendChild(delBtn);
        ti.appendChild(acts);
        dl.appendChild(ti);
      });
      dayWrap.appendChild(dl);
      content.appendChild(dayWrap);
    });
  }

  monthSel.addEventListener('change',e=>{ selMonth=parseInt(e.target.value,10); load(); });
  yearSel.addEventListener('change',e=>{ selYear=parseInt(e.target.value,10); load(); });
  allBtn.addEventListener('click',()=>{ mode='all'; allBtn.className='act'; monBtn.className=''; yrBtn.className=''; frow.style.display='none'; load(); });
  monBtn.addEventListener('click',()=>{ mode='monthly'; allBtn.className=''; monBtn.className='act'; yrBtn.className=''; frow.style.display=''; monthSel.style.display=''; load(); });
  yrBtn.addEventListener('click',()=>{ mode='yearly'; allBtn.className=''; monBtn.className=''; yrBtn.className='act'; frow.style.display=''; monthSel.style.display='none'; load(); });

  load();
  return pg;
}

function buildStats(){
  const myTxs=state.txs;
  const now=new Date();
  let selMonth=statusMonth; // 0 = All Months
  let selYear=statusYear; // 0 = All Years

  const pg=h('div',{class:'page fade'});
  pg.appendChild(h('div',{class:'page-hdr'},h('h2',{},'📊 Statistics')));
  const sc=h('div',{class:'sum-cards'});
  pg.appendChild(sc);

  // Build the summary cards from the currently selected month/year filter so
  // Total Income / Total Expenses / Balance always match the visible report.
  function buildSumCards(list){
    const inc=list.filter(t=>t.type==='income').reduce((s,t)=>s+(+t.amount),0);
    const ex =list.filter(t=>t.type==='expense').reduce((s,t)=>s+(+t.amount),0);
    sc.innerHTML='';
    sc.appendChild(h('div',{class:'sum-c'},h('div',{class:'sum-lbl'},'Total Income'),h('div',{class:'sum-val gr'},fmt(inc))));
    sc.appendChild(h('div',{class:'sum-c'},h('div',{class:'sum-lbl'},'Total Expenses'),h('div',{class:'sum-val rd'},fmt(ex))));
    if(list.length){
      sc.appendChild(h('div',{class:'sum-c'},h('div',{class:'sum-lbl'},'Balance'),h('div',{class:'sum-val '+(inc-ex>=0?'gr':'rd')},fmt(inc-ex))));
    }
  }

  // Month + Year filters (All Months / All Years supported)
  const filterRow=h('div',{class:'filter-row'});
  const monthSel=h('select',{});
  monthSel.appendChild(h('option',{value:0},'All Months'));
  MONTHS.forEach((m,i)=>{ const o=h('option',{value:i+1},m); if(i+1===selMonth) o.selected=true; monthSel.appendChild(o); });
  const yearSel=h('select',{});
  yearSel.appendChild(h('option',{value:0},'All Years'));
  const yrs=[...new Set(myTxs.map(t=>+t.date.slice(0,4)))];
  yrs.push(now.getFullYear());
  [...new Set(yrs)].sort((a,b)=>b-a).forEach(y=>{ const o=h('option',{value:y},y); if(y===selYear) o.selected=true; yearSel.appendChild(o); });
  filterRow.appendChild(monthSel);
  filterRow.appendChild(yearSel);
  const clearStatsBtn=h('button',{type:'button',class:'btn btn-xs clear-ui',title:'Hide the currently displayed entries for this period (records stay in the database — Reports & PDFs are unaffected)',onclick:()=>{
    const ids=filteredRaw().map(t=>+t.id);
    if(!ids.length){ toast('Nothing to clear for this period.'); return; }
    confirmClear('Are you sure you want to clear all entries?',async ()=>{
      try{ await persistClear(ids); render(); toast('Cleared '+ids.length+' entr'+(ids.length===1?'y':'ies')+' from this view — database unchanged.'); }
      catch(err){ toast(err.message); }
    });
  }},h('i',{class:'fas fa-broom'}),' Clear');
  filterRow.appendChild(clearStatsBtn);
  pg.appendChild(filterRow);

  // ── PDF Reports: Daily / Monthly / Yearly downloads ──
  const repCard = h('div',{class:'card pdf-card'});
  repCard.appendChild(h('div',{class:'sh'},h('h3',{},'📄 Reports'),h('span',{class:'sh-sub'},'PDF Download')));
  const repBtns = h('div',{class:'pdf-btns'});
  const bDaily = h('button',{class:'btn btn-p'},h('i',{class:'fas fa-calendar-day'}),' Download Daily PDF');
  const bMonthly = h('button',{class:'btn btn-d'},h('i',{class:'fas fa-calendar'}),' Download Monthly PDF');
  const bYearly = h('button',{class:'btn btn-o'},h('i',{class:'fas fa-calendar'}),' Download Yearly PDF');
  const bCategory = h('button',{class:'btn',style:'background:var(--grad);color:#fff'},h('i',{class:'fas fa-layer-group'}),' Download Category-wise PDF');
  repBtns.appendChild(bDaily); repBtns.appendChild(bMonthly); repBtns.appendChild(bYearly); repBtns.appendChild(bCategory);
  repCard.appendChild(repBtns);

  const pDaily = h('div',{class:'pdf-pane'});
  pDaily.appendChild(h('div',{class:'fg'},h('label',{},'Select a date')));
  const dInp = h('input',{class:'inp inp-bare',type:'date',value:toDay()});
  pDaily.appendChild(dInp);
  const dGo = h('button',{class:'btn btn-p btn-sm'},h('i',{class:'fas fa-file-pdf'}),' Generate PDF');
  pDaily.appendChild(dGo);

  const pMonthly = h('div',{class:'pdf-pane'});
  pMonthly.appendChild(h('div',{class:'fg'},h('label',{},'Select month and year')));
  const mRow = h('div',{class:'pdf-row'});
  const mSel = h('select',{});
  MONTHS.forEach((mm,i)=>{ const o=h('option',{value:i+1},mm); if(i+1===statusMonth) o.selected=true; mSel.appendChild(o); });
  const ySel = h('select',{});
  expYears().forEach(yr=>{ const o=h('option',{value:yr},yr); if(yr===statusYear) o.selected=true; ySel.appendChild(o); });
  mRow.appendChild(mSel); mRow.appendChild(ySel);
  pMonthly.appendChild(mRow);
  const mGo = h('button',{class:'btn btn-p btn-sm'},h('i',{class:'fas fa-file-pdf'}),' Generate PDF');
  pMonthly.appendChild(mGo);

  const pYearly = h('div',{class:'pdf-pane'});
  pYearly.appendChild(h('div',{class:'fg'},h('label',{},'Select a year')));
  const yRow = h('div',{class:'pdf-row'});
  const ySel2 = h('select',{});
  expYears().forEach(yr=>{ const o=h('option',{value:yr},yr); if(yr===statusYear) o.selected=true; ySel2.appendChild(o); });
  yRow.appendChild(ySel2);
  pYearly.appendChild(yRow);
  const yGo = h('button',{class:'btn btn-p btn-sm'},h('i',{class:'fas fa-file-pdf'}),' Generate PDF');
  pYearly.appendChild(yGo);

  const pCategory = h('div',{class:'pdf-pane'});
  pCategory.appendChild(h('div',{class:'fg'},h('label',{},'Select a year (or All Years)')));
  const cySel = h('select',{});
  cySel.appendChild(h('option',{value:0},'All Years'));
  expYears().forEach(yr=>{ const o=h('option',{value:yr},yr); if(yr===statusYear) o.selected=true; cySel.appendChild(o); });
  pCategory.appendChild(cySel);
  const cGo = h('button',{class:'btn btn-p btn-sm'},h('i',{class:'fas fa-file-pdf'}),' Generate PDF');
  pCategory.appendChild(cGo);

  repCard.appendChild(pDaily); repCard.appendChild(pMonthly); repCard.appendChild(pYearly); repCard.appendChild(pCategory);
  pg.appendChild(repCard);

  const panes = [pDaily, pMonthly, pYearly, pCategory];
  let openPane = null;
  const togglePane = p => { panes.forEach(x=>x.classList.remove('open')); if(openPane===p){ openPane=null; } else { openPane=p; p.classList.add('open'); } };
  bDaily.addEventListener('click',()=>togglePane(pDaily));
  bMonthly.addEventListener('click',()=>togglePane(pMonthly));
  bYearly.addEventListener('click',()=>togglePane(pYearly));
  bCategory.addEventListener('click',()=>togglePane(pCategory));
  dGo.addEventListener('click',async()=>{ if(!dInp.value){ toast('Please select a date first.'); return; } dGo.disabled=true; try{ await downloadDailyPdf(dInp.value); }finally{ dGo.disabled=false; } });
  mGo.addEventListener('click',async()=>{ mGo.disabled=true; try{ await downloadMonthlyPdf(+mSel.value, +ySel.value); }finally{ mGo.disabled=false; } });
  yGo.addEventListener('click',async()=>{ yGo.disabled=true; try{ await downloadYearlyPdf(+ySel2.value); }finally{ yGo.disabled=false; } });
  cGo.addEventListener('click',async()=>{ cGo.disabled=true; try{ await downloadCategoryPdf(+cySel.value); }finally{ cGo.disabled=false; } });

  const content=h('div',{});
  pg.appendChild(content);

  function filteredRaw(){
    let list=myTxs;
    if(selYear>0) list=list.filter(t=>+t.date.slice(0,4)===selYear);
    if(selMonth>0) list=list.filter(t=>+t.date.slice(5,7)===selMonth);
    return list;
  }
  function filteredList(){ return filteredRaw().filter(t=>!isTxCleared(t)); }
  function sumOf(list,type){ return list.filter(t=>t.type===type).reduce((s,t)=>s+t.amount,0); }
  function periodLabel(){
    if(selMonth>0&&selYear>0) return MONTHS[selMonth-1]+' '+selYear;
    if(selMonth===0&&selYear>0) return 'Year '+selYear;
    if(selMonth>0) return MONTHS[selMonth-1]+' (All Years)';
    return 'Complete History';
  }
  function monthBlock(label,list){
    const inc=sumOf(list,'income'), ex=sumOf(list,'expense');
    const card=h('div',{class:'st-month'});
    card.appendChild(h('div',{class:'st-month-name'},label));
    const vals=h('div',{class:'st-month-vals'});
    vals.appendChild(h('span',{class:'st-in'},'In '+fmt(inc)));
    vals.appendChild(h('span',{class:'st-ex'},'Ex '+fmt(ex)));
    vals.appendChild(h('span',{class:inc-ex>=0?'st-bal':'st-bal neg'},'Bal '+fmt(inc-ex)));
    card.appendChild(vals);
    return card;
  }

  function buildReport(){
    const list=filteredList();
    const wrap=h('div',{});
    wrap.appendChild(h('div',{class:'pv-title'},periodLabel(),h('small',{},'Income · Expense · Balance')));
    if(!list.length){
      wrap.appendChild(h('div',{class:'empty'},h('div',{class:'eic'},'📭'),h('p',{},'No records found for this period.')));
      return wrap;
    }
    const inc=sumOf(list,'income'), ex=sumOf(list,'expense');
    const grid=h('div',{class:'mstat-grid'});
    grid.appendChild(mstat('Total Income', fmt(inc), 'fa-arrow-down', 'gr'));
    grid.appendChild(mstat('Total Expense', fmt(ex), 'fa-arrow-up', 'rd'));
    grid.appendChild(mstat('Balance', fmt(inc-ex), 'fa-scale-balanced', inc-ex>=0?'gr':'rd'));
    grid.appendChild(mstat('Records', list.length+(list.length!==1?' records':' record'), 'fa-receipt'));
    wrap.appendChild(grid);

    if(selMonth===0){
      // Grouped history: All Months -> by year then month
      const groups={};
      list.forEach(t=>{ const key=t.date.slice(0,7); (groups[key]=groups[key]||[]).push(t); });
      const byYear={};
      Object.keys(groups).sort().forEach(k=>{ const y=k.slice(0,4); (byYear[y]=byYear[y]||[]).push(k); });
      Object.keys(byYear).sort((a,b)=>b-a).forEach(y=>{
        const yWrap=h('div',{class:'st-year'});
        yWrap.appendChild(h('div',{class:'st-year-hdr'},'📅 '+y));
        byYear[y].forEach(k=>{
          yWrap.appendChild(monthBlock(MONTHS[+k.slice(5,7)-1]+' '+y, groups[k]));
        });
        wrap.appendChild(yWrap);
      });
    } else {
      // Specific month -> category-wise + details
      const incMap={}, expMap={};
      list.forEach(t=>{ const m=(t.type==='income'?incMap:expMap); m[t.cat]=(m[t.cat]||0)+t.amount; });
      if(Object.keys(incMap).length){
        wrap.appendChild(sectionTitle('Income'));
        wrap.appendChild(catList(incMap,'income'));
      }
      if(Object.keys(expMap).length){
        wrap.appendChild(sectionTitle('Expenses'));
        wrap.appendChild(catList(expMap,'expense'));
      }
      wrap.appendChild(sectionTitle('Transactions'));
      const days={};
      list.slice().sort((a,b)=>b.date.localeCompare(a.date)).forEach(t=>{ (days[t.date]=days[t.date]||[]).push(t); });
      Object.keys(days).forEach(date=>{
        const dayWrap=h('div',{});
        dayWrap.appendChild(h('div',{class:'day-hdr'},fmtDate(date)));
        const tl=h('div',{class:'tx-list'});
        days[date].forEach(tx=>tl.appendChild(txRow(tx)));
        dayWrap.appendChild(tl);
        wrap.appendChild(dayWrap);
      });
    }
    return wrap;
  }

  function refresh(){
    content.innerHTML='';
    buildSumCards(filteredList());
    content.appendChild(buildReport());
  }

  monthSel.addEventListener('change',e=>{ selMonth=parseInt(e.target.value,10); statusMonth=selMonth; refresh(); });
  yearSel.addEventListener('change',e=>{ selYear=parseInt(e.target.value,10); statusYear=selYear; refresh(); });

  refresh();
  return pg;
}

// ─── TRANSACTION HISTORY (search + filter across all of the user's transactions) ──
function buildTransactionHistory(){
  const pg=h('div',{class:'page fade'});
  const phdr=h('div',{class:'page-hdr'});
  const bb=h('button',{class:'back-btn'},h('i',{class:'fas fa-chevron-left'}));
  bb.addEventListener('click',()=>{ setPage('dashboard'); render(); });
  phdr.appendChild(bb);
  phdr.appendChild(h('h2',{},'Transaction History'));
  pg.appendChild(phdr);

  let q='', catF='all', methodF='all', statusF='all', mode='all';
  const now=new Date();
  let selMonth=now.getMonth()+1, selYear=now.getFullYear();

  const searchRow=h('div',{class:'search-row fg'});
  const searchInp=h('input',{class:'inp',placeholder:'Search payee, notes or reference...'});
  searchInp.addEventListener('input',e=>{ q=e.target.value.trim().toLowerCase(); refresh(); });
  searchRow.appendChild(h('div',{class:'iw'},h('i',{class:'fas fa-search ic'}),searchInp));
  pg.appendChild(searchRow);

  const filterRow=h('div',{class:'filter-row'});
  const catSel=h('select',{});
  catSel.appendChild(h('option',{value:'all'},'All Categories'));
  const allCats=[...new Set(state.txs.map(t=>t.cat))].sort();
  allCats.forEach(c=>catSel.appendChild(h('option',{value:c},c)));
  catSel.addEventListener('change',e=>{ catF=e.target.value; refresh(); });
  const methodSel=h('select',{});
  methodSel.appendChild(h('option',{value:'all'},'All Methods'));
  PAY_METHODS.forEach(m=>methodSel.appendChild(h('option',{value:m},m.charAt(0).toUpperCase()+m.slice(1))));
  methodSel.addEventListener('change',e=>{ methodF=e.target.value; refresh(); });
  const statusSel=h('select',{});
  statusSel.appendChild(h('option',{value:'all'},'All Statuses'));
  STATUSES.forEach(s=>statusSel.appendChild(h('option',{value:s},s.charAt(0).toUpperCase()+s.slice(1))));
  statusSel.addEventListener('change',e=>{ statusF=e.target.value; refresh(); });
  filterRow.appendChild(catSel); filterRow.appendChild(methodSel); filterRow.appendChild(statusSel);
  pg.appendChild(filterRow);

  const tog=h('div',{class:'tab-tog'});
  const allBtn=h('button',{class:'act'},'All');
  const monBtn=h('button',{},'Monthly');
  const yrBtn=h('button',{},'Yearly');
  tog.appendChild(allBtn); tog.appendChild(monBtn); tog.appendChild(yrBtn);
  pg.appendChild(tog);
  const ev=h('div',{class:'ev-filters'});
  const monthSel=h('select',{});
  MONTHS.forEach((m,i)=>{ const o=h('option',{value:i+1},m); if(i+1===selMonth) o.selected=true; monthSel.appendChild(o); });
  const yearSel=h('select',{});
  const yrs=[...new Set(state.txs.map(t=>+t.date.slice(0,4)))]; yrs.push(now.getFullYear());
  [...new Set(yrs)].sort((a,b)=>b-a).forEach(y=>{ const o=h('option',{value:y},y); if(y===selYear) o.selected=true; yearSel.appendChild(o); });
  monthSel.addEventListener('change',e=>{ selMonth=parseInt(e.target.value,10); refresh(); });
  yearSel.addEventListener('change',e=>{ selYear=parseInt(e.target.value,10); refresh(); });
  ev.appendChild(monthSel); ev.appendChild(yearSel);
  ev.style.display='none';
  pg.appendChild(ev);
  allBtn.addEventListener('click',()=>{ mode='all'; allBtn.className='act'; monBtn.className=''; yrBtn.className=''; ev.style.display='none'; refresh(); });
  monBtn.addEventListener('click',()=>{ mode='monthly'; allBtn.className=''; monBtn.className='act'; yrBtn.className=''; ev.style.display=''; monthSel.style.display=''; refresh(); });
  yrBtn.addEventListener('click',()=>{ mode='yearly'; allBtn.className=''; monBtn.className=''; yrBtn.className='act'; ev.style.display=''; monthSel.style.display='none'; refresh(); });

  const content=h('div',{});
  pg.appendChild(content);

  function matches(t){
    if(catF!=='all' && t.cat!==catF) return false;
    if(methodF!=='all' && (t.paymentMethod||'cash')!==methodF) return false;
    if(statusF!=='all' && (t.status||'completed')!==statusF) return false;
    if(mode==='monthly' && (+t.date.slice(5,7)!==selMonth || +t.date.slice(0,4)!==selYear)) return false;
    if(mode==='yearly' && +t.date.slice(0,4)!==selYear) return false;
    if(q){
      const hay=[t.payeeName,t.notes,t.txnRef,String(t.id),t.cat].filter(Boolean).join(' ').toLowerCase();
      if(!hay.includes(q)) return false;
    }
    return true;
  }

  function refresh(){
    content.innerHTML='';
    const list=state.txs.filter(t=>!isTxCleared(t)).filter(matches).slice().sort((a,b)=>b.date.localeCompare(a.date)||(b.id||0)-(a.id||0));
    if(!list.length){
      content.appendChild(h('div',{class:'empty'},h('div',{class:'eic'},'📭'),h('p',{},'No transactions match your search/filters.')));
      return;
    }
    content.appendChild(h('div',{style:'font-size:11px;color:var(--light);font-weight:700;margin-bottom:10px'},`${list.length} result${list.length!==1?'s':''}`));
    const days={};
    list.forEach(t=>{ (days[t.date]=days[t.date]||[]).push(t); });
    Object.keys(days).forEach(date=>{
      const dayWrap=h('div',{});
      dayWrap.appendChild(h('div',{class:'day-hdr'},fmtDate(date)));
      const tl=h('div',{class:'tx-list'});
      days[date].forEach(tx=>{
        const ti=h('div',{class:'tx'});
        ti.appendChild(h('div',{class:`tx-ic ${tx.type}`},AICONS[tx.cat]||'📋'));
        const bd=h('div',{class:'tx-bd'});
        if(tx.payeeName){
          bd.appendChild(h('div',{class:'tx-payee'},tx.payeeName));
          const sub=h('div',{class:'tx-sub'},h('span',{},tx.cat));
          if(tx.paymentMethod && tx.paymentMethod!=='cash') sub.appendChild(h('span',{class:'pay-method-badge'},tx.paymentMethod));
          if(tx.status && tx.status!=='completed') sub.appendChild(h('span',{class:`status-badge ${tx.status}`},tx.status));
          bd.appendChild(sub);
        } else {
          bd.appendChild(h('div',{class:'tx-cat'},tx.cat));
        }
        if(tx.items && tx.items.length){
          tx.items.slice(0,3).forEach(it=>bd.appendChild(h('div',{class:'txh-item-line'},h('span',{class:'n'},it.name+(it.qty?' × '+it.qty+(it.unit?(' '+it.unit):''):'')),h('span',{},fmt(it.total)))));
        } else if(tx.notes){
          bd.appendChild(h('div',{class:'tx-nt'},tx.notes));
        }
        ti.appendChild(bd);
        ti.appendChild(h('div',{class:`tx-am ${tx.type}`},(tx.type==='income'?'+':'-')+fmt(tx.amount)));
        const acts=h('div',{class:'tx-acts'});
        const editBtn=h('button',{type:'button',class:'tx-act',title:'Edit'},h('i',{class:'fas fa-pen'}));
        editBtn.addEventListener('click',()=>{ showEditTxModal(tx, refresh); });
        const delBtn=h('button',{type:'button',class:'tx-act del',title:'Delete'},h('i',{class:'fas fa-trash'}));
        delBtn.addEventListener('click',async ()=>{
          delBtn.disabled=true;
          try { await api('api/transactions.php?id='+tx.id, { method:'DELETE' }); await refreshAll(); toast('Deleted!'); refresh(); }
          catch (err) { toast(err.message); delBtn.disabled=false; }
        });
        acts.appendChild(editBtn); acts.appendChild(delBtn);
        ti.appendChild(acts);
        tl.appendChild(ti);
      });
      dayWrap.appendChild(tl);
      content.appendChild(dayWrap);
    });
  }

  refresh();
  return pg;
}

function buildProfile(){
  const pg=h('div',{class:'page fade'});
  const initials=state.user.name.charAt(0).toUpperCase();
  const ph=h('div',{class:'ph'},
    h('div',{class:'avatar'},initials),
    h('div',{class:'pname'},state.user.name),
    h('div',{class:'pemail'},state.user.email)
  );
  if(state.user.is_admin===1){
    ph.appendChild(h('div',{class:'admin-badge'},h('i',{class:'fas fa-shield-halved'}),' Admin'));
  }
  if(state.mySalary){
    ph.appendChild(h('div',{class:'psalary'},'💰 '+fmt(state.mySalary.amount)+' / month'));
  }
  pg.appendChild(ph);
  const tabs=h('div',{class:'ptabs'});
  const t1=h('button',{class:'ptab act'},'Profile');
  const t2=h('button',{class:'ptab'},'Password');
  const body=h('div',{});
  const showProfileTab=()=>{
    t1.className='ptab act'; t2.className='ptab';
    body.innerHTML='';
    let name=state.user.name,email=state.user.email,gender=state.user.gender||'male';
    const fc=h('div',{class:'fc'});
    const errD=h('div',{}); fc.appendChild(errD);
    const ni=h('input',{class:'inp',type:'text',value:name}); ni.addEventListener('input',e=>name=e.target.value);
    const ei=h('input',{class:'inp',type:'email',value:email}); ei.addEventListener('input',e=>email=e.target.value);
    fc.appendChild(h('div',{class:'fg'},h('label',{},'Full Name'),h('div',{class:'iw'},h('i',{class:'fas fa-user ic'}),ni)));
    fc.appendChild(h('div',{class:'fg'},h('label',{},'Email Address'),h('div',{class:'iw'},h('i',{class:'fas fa-envelope ic'}),ei)));
    const gFg=h('div',{class:'fg'});
    gFg.appendChild(h('label',{},'Gender'));
    const gDiv=h('div',{class:'chips'});
    [['male','Male'],['female','Female']].forEach(v=>{
      const chip=h('div',{class:'chip'+(v[0]===gender?' sel':'')},v[1]);
      chip.addEventListener('click',()=>{ gDiv.querySelectorAll('.chip').forEach(c=>c.classList.remove('sel')); chip.classList.add('sel'); gender=v[0]; });
      gDiv.appendChild(chip);
    });
    gFg.appendChild(gDiv); fc.appendChild(gFg);
    const sb=h('button',{class:'btn btn-p'},h('i',{class:'fas fa-save'}),' Save Changes');
    sb.addEventListener('click',async ()=>{
      if(!name||!email){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Name and email are required.')); return; }
      sb.disabled=true;
      try {
        const d = await api('api/profile.php', { method:'PUT', body: JSON.stringify({ action:'profile', name, email, gender }) });
        state.user = d.user;
        ph.querySelector('.pname').textContent=name; ph.querySelector('.pemail').textContent=email; ph.querySelector('.avatar').textContent=name.charAt(0).toUpperCase();
        errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-s'},h('i',{class:'fas fa-circle-check'}),' Profile updated!'));
        sb.disabled=false;
      } catch (err) {
        errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' '+err.message));
        sb.disabled=false;
      }
    });
    fc.appendChild(sb);
    const lb=h('button',{class:'btn btn-g'},h('i',{class:'fas fa-sign-out-alt'}),' Logout');
    lb.addEventListener('click',logout);
    fc.appendChild(lb); body.appendChild(fc);
  };
  const showPassTab=()=>{
    t1.className='ptab'; t2.className='ptab act';
    body.innerHTML='';
    let cur='',np='',cp='';
    const fc=h('div',{class:'fc'});
    const errD=h('div',{}); fc.appendChild(errD);
    const ci=h('input',{class:'inp',type:'password',placeholder:'Current password'}); ci.addEventListener('input',e=>cur=e.target.value);
    const ni=h('input',{class:'inp',type:'password',placeholder:'Min. 8 characters'}); ni.addEventListener('input',e=>np=e.target.value);
    const cfi=h('input',{class:'inp',type:'password',placeholder:'Repeat new password'}); cfi.addEventListener('input',e=>cp=e.target.value);
    fc.appendChild(h('div',{class:'fg'},h('label',{},'Current Password'),h('div',{class:'iw'},h('i',{class:'fas fa-lock ic'}),ci)));
    fc.appendChild(h('div',{class:'fg'},h('label',{},'New Password'),h('div',{class:'iw'},h('i',{class:'fas fa-lock ic'}),ni)));
    fc.appendChild(h('div',{class:'fg'},h('label',{},'Confirm New Password'),h('div',{class:'iw'},h('i',{class:'fas fa-lock ic'}),cfi)));
    const sb=h('button',{class:'btn btn-p'},h('i',{class:'fas fa-key'}),' Change Password');
    sb.addEventListener('click',async ()=>{
      if(!cur||!np||!cp){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' All fields required.')); return; }
      if(np.length<8){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Password must be 8+ characters.')); return; }
      if(np!==cp){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Passwords do not match.')); return; }
      sb.disabled=true;
      try {
        const d = await api('api/profile.php', { method:'PUT', body: JSON.stringify({ action:'password', current:cur, new:np }) });
        errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-s'},h('i',{class:'fas fa-circle-check'}),' Password changed!'));
        ci.value=''; ni.value=''; cfi.value=''; cur=np=cp=''; sb.disabled=false;
      } catch (err) {
        errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' '+err.message));
        sb.disabled=false;
      }
    });
    fc.appendChild(sb); body.appendChild(fc);
  };
  t1.addEventListener('click',showProfileTab);
  t2.addEventListener('click',showPassTab);
  tabs.appendChild(t1); tabs.appendChild(t2);
  pg.appendChild(tabs); pg.appendChild(body);
  if(profileTab==='password') showPassTab(); else showProfileTab();
  return pg;
}

// ─── SETTINGS PAGE ────────────────────────────────────────────────────────────
function settingsRow(iconCls, iconBg, title, sub, onClick){
  const row=h('div',{class:'settings-row'});
  row.appendChild(h('div',{class:'settings-row-icon '+iconBg},h('i',{class:'fas '+iconCls})));
  const body=h('div',{class:'settings-row-body'});
  body.appendChild(h('div',{class:'settings-row-title'},title));
  body.appendChild(h('div',{class:'settings-row-sub'},sub));
  row.appendChild(body);
  row.appendChild(h('i',{class:'fas fa-chevron-right settings-row-arrow'}));
  row.addEventListener('click',onClick);
  return row;
}
function buildSettings(){
  if(settingsPage && settingsPage.startsWith('admin') && state.user.is_admin!==1) settingsPage=null;
  if(settingsPage==='salary-management') return buildSalaryManagement();
  if(settingsPage==='salary-history') return buildSalaryHistory();
  if(settingsPage==='admin-users') return buildAdminUsers();
  if(settingsPage==='admin-view-user') return buildAdminViewUser();
  if(settingsPage==='admin-password') return buildAdminPassword();

  const pg=h('div',{class:'page fade'});
  pg.appendChild(h('div',{class:'page-hdr'},h('h2',{},'⚙️ Settings')));

  if(state.user.is_admin===1){
    const admSection=h('div',{class:'settings-section'});
    admSection.appendChild(h('div',{class:'settings-section-title'},'Admin Controls'));
    const admCard=h('div',{class:'settings-card'});
    admCard.appendChild(settingsRow('fa-users', 'sri-purple', 'User Management', 'View all users and their roles', ()=>{ settingsPage='admin-users'; render(); }));
    admCard.appendChild(settingsRow('fa-receipt', 'sri-green', 'View User Expenses', 'Inspect any user\'s income & expenses', ()=>{ settingsPage='admin-view-user'; render(); }));
    admCard.appendChild(settingsRow('fa-key', 'sri-red', 'Change Admin Password', 'Update the administrator password', ()=>{ settingsPage='admin-password'; render(); }));
    admSection.appendChild(admCard);
    pg.appendChild(admSection);

    const salSection=h('div',{class:'settings-section'});
    salSection.appendChild(h('div',{class:'settings-section-title'},'Salary Configuration'));
    const salCard=h('div',{class:'settings-card'});
    salCard.appendChild(settingsRow('fa-money-bill-wave', 'sri-purple', 'Salary Management', 'Set & update employee salaries by month/year', ()=>{ settingsPage='salary-management'; render(); }));
    salCard.appendChild(settingsRow('fa-clock-rotate-left', 'sri-green', 'Salary History', 'View all past salary changes with filters', ()=>{ settingsPage='salary-history'; render(); }));
    salSection.appendChild(salCard);
    pg.appendChild(salSection);
  }

  const accSection=h('div',{class:'settings-section'});
  accSection.appendChild(h('div',{class:'settings-section-title'},'Account'));
  const accCard=h('div',{class:'settings-card'});
  accCard.appendChild(settingsRow('fa-user', 'sri-purple', 'My Profile', state.user.name+' · '+state.user.email, ()=>{ profileTab='profile'; setPage('profile'); render(); }));
  accCard.appendChild(settingsRow('fa-robot', 'sri-purple', 'AI Assistant', 'Ask questions about your spending & income', aiOpenOverlay));
  accCard.appendChild(buildThemeSettingsRow());
  accCard.appendChild(settingsRow('fa-clock-rotate-left', 'sri-orange', 'Transaction History', 'Search & filter all your transactions', ()=>{ setPage('tx-history'); render(); }));
  accCard.appendChild(settingsRow('fa-key', 'sri-green', 'Change Password', 'Update your password', ()=>{ profileTab='password'; setPage('profile'); render(); }));
  accCard.appendChild(settingsRow('fa-sign-out-alt', 'sri-red', 'Logout', 'Sign out of your account', logout));
  accCard.appendChild(settingsRow('fa-trash-alt', 'sri-red', 'Delete All Data', 'Permanently remove all transactions, events, AI chats & categories', grmDeleteAllDataConfirm));
  accSection.appendChild(accCard);
  pg.appendChild(accSection);

  if(state.user.is_admin!==1){
    const infoBox=h('div',{class:'al al-w',style:'margin-top:4px'});
    infoBox.appendChild(h('i',{class:'fas fa-circle-info'}));
    infoBox.appendChild(document.createTextNode(' Admin controls are available to the administrator only.'));
    pg.appendChild(infoBox);
  }

  return pg;
}

// ─── AI FINANCIAL ASSISTANT ───────────────────────────────────────────────────
// Floating "Ask AI" button (+ Settings entry) opens a full-height chat overlay.
// The backend always replies from the user's OWN records (services/FinanceData.php)
// with a server-side OpenAI call when a key is configured, or a deterministic
// offline answer otherwise — so the chat never breaks without an API key.
let AI = {
  open:false,   // overlay visible?
  convs:[],     // [{id,title,msg_count}] newest first
  convId:0,     // active conversation id (0 = none yet)
  msgs:[],      // [{role:'user'|'assistant'|'error', message, cards}]
  sending:false,
  note:'',
  el:null,      // cached overlay elements
};

const AI_QUICK = [
  'How much did I spend this month?',
  'What are my top expense categories?',
  'How does this month compare to last month?',
  'What is my balance?',
  'How do I add an expense?',
  'How do I generate a PDF report?',
];

function aiOpenOverlay(){
  if(AI.el) return;
  const ov=h('div',{class:'ai-overlay'});
  ov.addEventListener('click',e=>{ if(e.target===ov) aiCloseOverlay(); });
  const panel=h('div',{class:'ai-panel'});

  const hdr=h('div',{class:'ai-hdr'});
  const left=h('div',{class:'ai-hdr-l'});
  left.appendChild(h('div',{class:'ai-hdr-t'},h('i',{class:'fas fa-robot'}),' AI Assistant'));
  const sub=h('div',{class:'ai-hdr-s'});
  sub.textContent='Ask about your spending & income, or how to use MoneyWise';
  left.appendChild(sub);
  const newBtn=h('button',{type:'button',class:'ai-hbtn',title:'New chat'},h('i',{class:'fas fa-plus'}));
  newBtn.addEventListener('click',aiNewConversation);
  const closeBtn=h('button',{type:'button',class:'ai-hbtn',title:'Close'},h('i',{class:'fas fa-xmark'}));
  closeBtn.addEventListener('click',aiCloseOverlay);
  hdr.appendChild(left); hdr.appendChild(newBtn); hdr.appendChild(closeBtn);
  panel.appendChild(hdr);

  const convs=h('div',{class:'ai-convs'}); panel.appendChild(convs);
  const msgs=h('div',{class:'ai-msgs'}); panel.appendChild(msgs);
  const suggest=h('div',{class:'ai-suggest'}); panel.appendChild(suggest);

  const bar=h('div',{class:'ai-input'});
  const ta=h('textarea',{class:'ai-ta',rows:1,placeholder:'Ask about your money…'});
  const send=h('button',{type:'button',class:'ai-send',title:'Send'},h('i',{class:'fas fa-paper-plane'}));
  const stop=h('button',{type:'button',class:'ai-stop',title:'Stop'},h('i',{class:'fas fa-stop'}));
  stop.style.display='none';
  stop.addEventListener('click',()=>{ if(AI._stopFn) AI._stopFn(); });
  const autoGrow=()=>{ ta.style.height='auto'; ta.style.height=Math.min(ta.scrollHeight,120)+'px'; };
  const setSending=(on)=>{
    if(!AI.el) return;
    if(on){ send.disabled=true; ta.disabled=true; send.style.display='none'; stop.style.display='flex'; }
    else { send.disabled=false; ta.disabled=false; send.style.display='flex'; stop.style.display='none'; AI.el.ta.focus(); }
  };
  const aiSetSending=setSending;
  const sendNow=()=>{ const t=ta.value.trim(); if(t && !AI.sending){ aiSend(t); ta.value=''; autoGrow(); ta.focus(); } };
  ta.addEventListener('input',autoGrow);
  ta.addEventListener('keydown',e=>{ if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); sendNow(); } });
  send.addEventListener('click',sendNow);
  bar.appendChild(ta); bar.appendChild(send);
  panel.appendChild(bar);

  ov.appendChild(panel);
  document.body.appendChild(ov);
  AI.el={ov,convs,msgs,suggest,ta,send,dim:setSending};
  AI.open=true;
  aiStatusNote(sub);
  if(AI.convs.length && !AI.convs.some(c=>c.id===AI.convId)) AI.convId=AI.convs[0].id;
  aiSelectConversation(AI.convId);
}

function aiCloseOverlay(){
  if(!AI.el) return;
  AI.el.ov.remove();
  AI.el=null;
  AI.open=false;
}

function aiStatusNote(sub){
  try{
    api('api/ai.php?action=status').then(d=>{
      if(!AI.el) return;
      sub.textContent = d.configured ? 'Assistant connected' : 'Ask about your spending & income';
    }).catch(()=>{});
  }catch(e){}
}

function aiChip(label, active, onClick){
  const chip=h('button',{type:'button',class:'ai-cchip'+(active?' act':''),title:label},label);
  chip.addEventListener('click',onClick);
  return chip;
}

async function aiRefreshConvs(){
  try{
    const d=await api('api/ai.php?action=conversations');
    AI.convs=d.conversations;
    if(AI.el) aiRenderConvs();
  }catch(e){}
}

function aiRenderConvs(){
  const row=AI.el.convs; row.innerHTML='';
  row.appendChild(aiChip('New chat', AI.convId===0, ()=>aiSelectConversation(0)));
  // Skip empty placeholder chats (0 messages) so the history list stays clean.
  AI.convs.filter(c=>c.msg_count>0).forEach(c=>{
    const wrap=h('div',{class:'ai-conv-item'});
    wrap.appendChild(aiChip(c.title, AI.convId===c.id, ()=>aiSelectConversation(c.id)));
    const del=h('button',{type:'button',class:'ai-conv-del',title:'Cancel / delete this chat history',onclick:()=>aiDeleteConversation(c.id)});
    del.appendChild(h('i',{class:'fas fa-xmark'}));
    wrap.appendChild(del);
    row.appendChild(wrap);
  });
}

async function aiDeleteConversation(id){
  if(AI.sending) return;
  try{
    await api('api/ai.php',{method:'POST',body:JSON.stringify({action:'delete', conversation:id})});
  }catch(e){ toast('Could not delete that conversation.'); return; }
  AI.convs=AI.convs.filter(c=>c.id!==id);
  if(AI.convId===id){ AI.convId=0; AI.msgs=[]; }
  aiRenderConvs();
  aiRenderMsgs();
}

async function aiSelectConversation(id){
  if(AI.sending) return;
  AI.convId=id; AI.msgs=[];
  aiRenderConvs();
  if(id){
    try{
      const d=await api('api/ai.php?action=messages&conversation='+id);
      AI.msgs=(d.messages||[]).map(m=>({role:m.role, message:m.message, cards:[], ts:m.created_at}));
    }catch(e){
      toast('Could not load that conversation.');
      AI.convId=0;
    }
  }
  aiRenderMsgs();
}

async function aiNewConversation(){
  if(AI.sending) return;
  try{
    const d=await api('api/ai.php',{method:'POST',body:JSON.stringify({action:'start'})});
    await aiRefreshConvs();
    aiSelectConversation(d.conversation.id);
  }catch(e){ toast('Could not start a new chat.'); }
}

// ─── Lightweight, injection-safe markdown renderer ─────────────────────────
// Never builds HTML strings from AI text. Instead we parse into a token list and
// build DOM nodes so any angle brackets / scripts in the reply stay inert text.
function aiMdEscape(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function aiMdText(root,text){
  // Inline: `code` and **bold**
  const rx=/`([^`]+)`|\*\*([^*]+)\*\*/g;
  let last=0, m;
  while((m=rx.exec(text))){
    if(m.index>last) root.appendChild(document.createTextNode(text.slice(last,m.index)));
    if(m[1]!==undefined){ const c=h('code',{}); c.textContent=m[1]; root.appendChild(c); }
    else { const s=h('strong',{}); s.textContent=m[2]; root.appendChild(s); }
    last=m.index+m[0].length;
  }
  if(last<text.length) root.appendChild(document.createTextNode(text.slice(last)));
}
function aiMdCodeBlock(text,lang){
  const wrap=h('div',{class:'ai-code'});
  const bar=h('div',{class:'ai-code-hdr'});
  bar.appendChild(h('span',{class:'ai-code-lang'},lang||'code'));
  const cp=h('button',{type:'button',class:'ai-code-copy',title:'Copy code'},h('i',{class:'fas fa-copy'}));
  cp.addEventListener('click',()=>{ navigator.clipboard.writeText(text); toast('Code copied.'); });
  bar.appendChild(cp);
  const pre=h('pre',{}); const code=h('code',{}); code.textContent=text; pre.appendChild(code);
  wrap.appendChild(bar); wrap.appendChild(pre);
  return wrap;
}
function aiMdRender(text){
  const frag=document.createDocumentFragment();
  const raw=text.replace(/\r\n?/g,"\n");
  // Fenced code blocks are split out first so inline rules never touch them.
  const lines=raw.split("\n");
  let i=0; let para=[];
  const flushPara=()=>{ if(!para.length) return; const p=h('p',{}); aiMdText(p,para.join(" ")); frag.appendChild(p); para=[]; };
  while(i<lines.length){
    const line=lines[i];
    if(/^```/.test(line.trim())){
      flushPara();
      const lang=line.trim().slice(3).trim();
      const buf=[]; i++;
      while(i<lines.length && !/^```/.test(lines[i].trim())){ buf.push(lines[i]); i++; }
      i++; // skip closing fence
      frag.appendChild(aiMdCodeBlock(buf.join("\n").trim(),lang));
    } else if(/^\s*(?:•|-|\*)\s+/.test(line.trim())){
      // Bullet list
      flushPara();
      const ul=h('ul',{}); 
      while(i<lines.length && /^\s*(?:•|-|\*)\s+/.test(lines[i].trim())){
        const li=h('li',{}); aiMdText(li,lines[i].replace(/^\s*(?:•|-|\*)\s+/,'').trim()); ul.appendChild(li); i++;
      }
      frag.appendChild(ul);
    } else if(line.trim()===''){
      flushPara(); i++;
    } else {
      para.push(line.trim()); i++;
    }
  }
  flushPara();
  return frag;
}
function aiTimeLabel(ts){
  if(!ts) return '';
  const d=new Date(ts);
  const hh=d.getHours()%12||12;
  return String(d.getMinutes()).padStart(2,'0')+' '+(d.getHours()<12?'am':'pm');
}
function aiActionRow(bubble, idx){
  const row=h('div',{class:'ai-actions'});
  const cp=h('button',{type:'button',class:'ai-action',title:'Copy'},h('i',{class:'fas fa-copy'}));
  cp.addEventListener('click',()=>{ navigator.clipboard.writeText(bubble.dataset.raw||''); toast('Copied.'); });
  row.appendChild(cp);
  const regen=h('button',{type:'button',class:'ai-action',title:'Regenerate'},h('i',{class:'fas fa-rotate-right'}));
  regen.addEventListener('click',()=>aiRegenerate(idx));
  row.appendChild(regen);
  return row;
}
function aiBubble(role,text,cards,timestamp,idx){
  const w=h('div',{class:'ai-msg-wrap'+(role==='user'?' ai-right':'')});
  const cls=role==='user'?'ai-user':(role==='error'?'ai-err':'ai-bot');
  const b=h('div',{class:'ai-msg '+cls});
  b.dataset.raw=text;
  if(role==='user'||role==='error'){
    b.textContent=text;
  } else {
    b.appendChild(aiMdRender(String(text)));
  }
  if(cards && cards.length){
    const cc=h('div',{class:'ai-cards'});
    cards.forEach(c=>cc.appendChild(h('div',{class:'ai-card'},h('span',{},c.label),h('strong',{},c.value))));
    b.appendChild(cc);
  }
  w.appendChild(b);
  if(timestamp){
    const t=h('div',{class:'ai-time'},h('i',{class:'fas fa-clock'}),aiTimeLabel(timestamp));
    w.appendChild(t);
  }
  if(role!=='error' && typeof idx==='number'){
    w.appendChild(aiActionRow(b, idx));
  }
  return w;
}

function aiShowTyping(){
  const t=h('div',{class:'ai-msg ai-msg-bot ai-typing'},h('span'),h('span'),h('span'));
  t.dataset.typing='1';
  AI.el.msgs.appendChild(t);
  AI.el.msgs.scrollTop=AI.el.msgs.scrollHeight;
}

function aiHideTyping(){
  AI.el.msgs.querySelectorAll('[data-typing="1"]').forEach(n=>n.remove());
}

function aiRenderMsgs(){
  const m=AI.el.msgs; m.innerHTML='';
  if(AI.msgs.length){
    AI.msgs.forEach((x,i)=>m.appendChild(aiBubble(x.role, x.message, x.cards||[], x.ts, i)));
  } else {
    // Fresh / empty chat: show a default welcome message, then quick chips.
    m.appendChild(aiBubble('bot', 'Welcome to MoneyWise! 👋 I can help with your spending, income and balance — and show you how to use every MoneyWise feature. Try "What is my balance?", "How much did I spend on food?", or "How do I add an expense?"'));
  }
  if(!AI.msgs.length){
    AI.el.suggest.innerHTML='';
    AI_QUICK.forEach(q=>{
      const s=h('button',{type:'button',class:'ai-qchip'},q);
      s.addEventListener('click',()=>aiSend(q));
      AI.el.suggest.appendChild(s);
    });
  } else {
    AI.el.suggest.innerHTML='';
  }
  m.scrollTop=m.scrollHeight;
}

function aiRegenerate(idx){
  if(AI.sending) return;
  const um=AI.msgs[idx];
  if(!um || um.role!=='assistant') return;
  // Re-send the prior user message (the one before this assistant turn).
  let ui=idx-1; while(ui>=0 && AI.msgs[ui].role!=='user') ui--;
  if(ui<0) return;
  // Drop everything from this assistant turn onward.
  AI.msgs=AI.msgs.slice(0, ui+1);
  aiRenderMsgs();
  aiSend(AI.msgs[ui].message);
}

async function aiSend(text){
  if(AI.sending || !text.trim()) return;
  AI.sending=true;
  if(AI.el && AI.el.dim) AI.el.dim(true);
  AI._stop=false;
  AI._stopFn=()=>{ AI._stop=true; };
  const now=Date.now();
  AI.msgs.push({role:'user', message:text, cards:[], ts:now});
  aiRenderMsgs();
  aiShowTyping();
  let data={ok:false,error:'Network error. Please try again.'};
  let httpStatus=0;
  try{
    const res=await fetch('api/ai.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action:'chat', conversation:AI.convId||0, message:text})});
    httpStatus=res.status;
    data=await res.json().catch(()=>({ok:false,error:'Invalid server response.'}));
  }catch(e){}
  aiHideTyping();
  if(data.ok && !AI._stop){
    AI.convId=data.conversation_id||AI.convId;
    const fullText=(data.reply||'').trim();
    AI.msgs.push({role:'assistant',message:fullText,cards:data.cards||[],ts:Date.now()});
    aiRenderMsgs();
    if(fullText) aiStreamReveal(AI.msgs.length-1, fullText);
    aiRefreshConvs();
  } else {
    if(!AI._stop){
      // Show a clean, non-technical error. Rate-limited responses and repeated
      // provider failures map to a friendly "busy" message instead of the raw error.
      const friendly = httpStatus===429
        ? 'The assistant is temporarily busy. Please try again in a moment.'
        : (data.reply || (!data.rate_limited && data.error ? data.error : 'Something went wrong. Please try again.'));
      AI.msgs.push({role:'error',message:friendly,cards:[],ts:Date.now()});
      aiRenderMsgs();
    }
  }
  AI.sending=false;
  if(AI.el && AI.el.dim) AI.el.dim(false);
}

// Simulated streaming: reveal the assistant's reply a few characters at a time so
// the answer "types out" (offline friendly — the provider is not required).
function aiStreamReveal(idx, full){
  if(!AI.el || idx<0 || idx>=AI.msgs.length) return;
  const bots=[...AI.el.msgs.querySelectorAll('.ai-msg.ai-bot')];
  const bubble=bots[bots.length-1];
  if(!bubble) return;
  const holder=document.createElement('div');
  holder.className='ai-stream-bot';
  let pos=0;
  const step=()=>{
    if(AI._stop || !AI.el){ bubble.innerHTML=''; bubble.appendChild(aiMdRender(full)); return; }
    pos += 26;
    const slice=full.slice(0,pos);
    bubble.innerHTML='';
    bubble.appendChild(aiMdRender(slice+(pos<full.length?'▍':'')));
    AI.el.msgs.scrollTop=AI.el.msgs.scrollHeight;
    if(pos<full.length) setTimeout(step, 18);
  };
  step();
}

// ─── ADMIN: USER MANAGEMENT ───────────────────────────────────────────────────
function confirmDeleteUser(u){
  const overlay=h('div',{class:'modal-overlay'});
  const sheet=h('div',{class:'modal-sheet'});
  sheet.appendChild(h('div',{class:'modal-handle'}));
  sheet.appendChild(h('div',{class:'modal-title'},'🗑️ Remove User'));
  sheet.appendChild(h('div',{style:'font-size:13px;color:var(--mid);margin-bottom:20px;line-height:1.6'},'Are you sure you want to delete this user?'));
  const info=h('div',{style:'background:#fef2f2;border-radius:var(--r-lg);padding:12px 14px;margin-bottom:20px;display:flex;align-items:center;gap:10px'});
  info.appendChild(h('div',{class:'emp-avatar',style:'background:#fecaca;color:#dc2626'},u.name.charAt(0).toUpperCase()));
  const in2=h('div',{});
  in2.appendChild(h('div',{style:'font-size:13px;font-weight:800;color:var(--dark)'},u.name));
  in2.appendChild(h('div',{style:'font-size:11px;color:var(--mid)'},u.email));
  info.appendChild(in2);
  sheet.appendChild(info);
  sheet.appendChild(h('div',{style:'font-size:12px;color:var(--mid);margin-bottom:18px'},'All of their income, expenses and salary records will also be removed. This cannot be undone.'));
  const delBtn=h('button',{class:'btn btn-d'},h('i',{class:'fas fa-trash'}),' Delete');
  delBtn.addEventListener('click',async ()=>{
    try {
      await api('api/admin.php?action=delete&userId='+u.id);
      overlay.remove();
      toast('User deleted.');
      const users=await api('api/admin.php?action=users');
      state.adminUsers=users.users;
      render();
    } catch (err) {
      overlay.remove();
      toast(err.message);
    }
  });
  const cancelBtn=h('button',{class:'btn',style:'background:var(--p5);color:var(--mid);margin-top:8px'},h('i',{class:'fas fa-times'}),' Cancel');
  cancelBtn.addEventListener('click',()=>overlay.remove());
  sheet.appendChild(delBtn);
  sheet.appendChild(cancelBtn);
  overlay.appendChild(sheet);
  overlay.addEventListener('click',e=>{ if(e.target===overlay) overlay.remove(); });
  document.body.appendChild(overlay);
}

function buildAdminUsers(){
  const pg=h('div',{class:'page fade'});
  const phdr=h('div',{class:'page-hdr'});
  const bb=h('button',{class:'back-btn'},h('i',{class:'fas fa-chevron-left'}));
  bb.addEventListener('click',()=>{ settingsPage=null; render(); });
  phdr.appendChild(bb);
  phdr.appendChild(h('h2',{},'User Management'));
  pg.appendChild(phdr);

  const users=state.adminUsers;
  if(!users.length){
    pg.appendChild(h('div',{class:'empty'},h('div',{class:'eic'},'👥'),h('p',{},'No users found.')));
    return pg;
  }
  users.forEach(u=>{
    const card=h('div',{class:'emp-card'});
    const av=h('div',{class:'emp-avatar'},u.name.charAt(0).toUpperCase());
    card.appendChild(av);
    const info=h('div',{class:'emp-info'});
    info.appendChild(h('div',{class:'emp-name'},u.name));
    info.appendChild(h('div',{class:'emp-email'},u.email));
    card.appendChild(info);
    if(u.is_admin===1){
      card.appendChild(h('div',{class:'admin-badge'},h('i',{class:'fas fa-shield-halved'}),' Admin'));
    } else {
      card.appendChild(h('div',{class:'emp-salary-badge',style:'background:#eef2ff;color:#7c3aed'},'User'));
      const delBtn=h('button',{class:'emp-del',title:'Remove User',style:'flex-shrink:0'},h('i',{class:'fas fa-trash'}));
      delBtn.addEventListener('click',()=>confirmDeleteUser(u));
      card.appendChild(delBtn);
    }
    let startX=null;
    card.addEventListener('touchstart',e=>{ startX=e.touches[0].clientX; });
    card.addEventListener('touchend',e=>{
      if(startX!=null){
        const dx=e.changedTouches[0].clientX-startX;
        startX=null;
        if(dx<-60) confirmDeleteUser(u);
      }
    });
    pg.appendChild(card);
  });
  return pg;
}

// ─── ADMIN: VIEW USER EXPENSES ────────────────────────────────────────────────
function buildAdminViewUser(){
  const pg=h('div',{class:'page fade'});
  const phdr=h('div',{class:'page-hdr'});
  const bb=h('button',{class:'back-btn'},h('i',{class:'fas fa-chevron-left'}));
  bb.addEventListener('click',()=>{ settingsPage=null; render(); });
  phdr.appendChild(bb);
  phdr.appendChild(h('h2',{},'View User Expenses'));
  pg.appendChild(phdr);

  const users=state.adminUsers.filter(u=>u.is_admin===0);
  if(!users.length){
    pg.appendChild(h('div',{class:'empty'},h('div',{class:'eic'},'👥'),h('p',{},'No users found.')));
    return pg;
  }

  const now=new Date();
  let selUser=users[0].id;
  let selMonth=now.getMonth()+1;
  let selYear=now.getFullYear();
  let currentTxs=[];

  const filterRow=h('div',{class:'filter-row'});
  const userSel=h('select',{});
  users.forEach(u=>userSel.appendChild(h('option',{value:u.id},u.name+' ('+u.email+')')));
  userSel.addEventListener('change',e=>{ selUser=parseInt(e.target.value,10); load(); });
  const monthSel=h('select',{});
  monthSel.appendChild(h('option',{value:0},'All Months'));
  MONTHS.forEach((m,i)=>{ const o=h('option',{value:i+1},m); if(i+1===selMonth) o.selected=true; monthSel.appendChild(o); });
  monthSel.addEventListener('change',e=>{ selMonth=parseInt(e.target.value,10); load(); });
  const yearSel=h('select',{});
  yearSel.appendChild(h('option',{value:0},'All Years'));
  yearSel.addEventListener('change',e=>{ selYear=parseInt(e.target.value,10); load(); });
  filterRow.appendChild(userSel);
  filterRow.appendChild(monthSel);
  filterRow.appendChild(yearSel);
  pg.appendChild(filterRow);

  if(state.user && state.user.is_admin===1){
    const clearRow=h('div',{class:'tx-clear-row',style:'margin-bottom:16px'});
    const clearBtn=h('button',{type:'button',class:'btn clear-ui-wide',title:"Hide this user's entries shown here (records stay in the database)"},h('i',{class:'fas fa-broom'}),' Clear Current View');
    clearBtn.addEventListener('click',()=>{
      const ids=currentTxs.map(t=>+t.id);
      if(!ids.length){ toast('Nothing to clear here.'); return; }
      confirmClear('Are you sure you want to clear all entries?',async ()=>{
        try{ await persistClear(ids); load(); toast('Cleared current view — database unchanged.'); }
        catch(err){ toast(err.message); }
      });
    });
    clearRow.appendChild(clearBtn);
    pg.appendChild(clearRow);
  }

  const content=h('div',{});
  pg.appendChild(content);

  function rebuildYears(years){
    const keep=yearSel.value;
    yearSel.innerHTML='';
    yearSel.appendChild(h('option',{value:0},'All Years'));
    const list=[...(years||[])];
    if(list.indexOf(now.getFullYear())<0) list.push(now.getFullYear());
    list.sort((a,b)=>b-a).forEach(y=>{ const o=h('option',{value:y},y); if(y===+keep||y===selYear) o.selected=true; yearSel.appendChild(o); });
  }

  async function load(){
    content.innerHTML='';
    content.appendChild(h('div',{class:'empty',style:'padding:28px'},h('div',{class:'eic'},'⏳'),h('p',{},'Loading...')));
    const q=new URLSearchParams({ action:'view', userId:selUser, month:selMonth, year:selYear });
    let d;
    try {
      d = await api('api/admin.php?'+q.toString());
    } catch (err) {
      content.innerHTML='';
      content.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' '+err.message));
      return;
    }
    content.innerHTML='';
    rebuildYears(d.availableYears||[]);
    const u=d.user||{name:'',email:''};
    const txs=d.transactions||[];
    currentTxs=txs;
    const s=d.summary||{income:0,expense:0,balance:0,incomeByCat:{},expenseByCat:{}};

    const title=h('div',{class:'pv-title'},u.name,h('small',{},u.email));
    content.appendChild(title);

    const period=selMonth>0&&selYear>0?MONTHS[selMonth-1]+' '+selYear
      :(selMonth>0?MONTHS[selMonth-1]+' (All Years)':(selYear>0?'Year '+selYear:'All Records'));
    const vis=txs.filter(t=>!isTxCleared(t));
    const vIn=vis.filter(t=>t.type==='income').reduce((x,t)=>x+(+t.amount),0);
    const vEx=vis.filter(t=>t.type==='expense').reduce((x,t)=>x+(+t.amount),0);
    const incMap={}, expMap={};
    vis.forEach(t=>{ const m=(t.type==='income'?incMap:expMap); m[t.cat]=(m[t.cat]||0)+(+t.amount); });
    const grid=h('div',{class:'mstat-grid'});
    grid.appendChild(mstat('Total Income', fmt(vIn), 'fa-arrow-down', 'gr'));
    grid.appendChild(mstat('Total Expense', fmt(vEx), 'fa-arrow-up', 'rd'));
    grid.appendChild(mstat('Balance', fmt(vIn-vEx), 'fa-scale-balanced', (vIn-vEx)>=0?'gr':'rd'));
    grid.appendChild(mstat('Period', period, 'fa-calendar'));
    content.appendChild(grid);

    if(Object.keys(incMap).length){
      content.appendChild(sectionTitle('Income'));
      content.appendChild(catList(incMap,'income'));
    }
    if(Object.keys(expMap).length){
      content.appendChild(sectionTitle('Expenses'));
      content.appendChild(catList(expMap,'expense'));
    }

    content.appendChild(sectionTitle('Transactions'));
    if(!vis.length){
      content.appendChild(h('div',{class:'empty'},h('div',{class:'eic'},'📭'),h('p',{},txs.length?'Records cleared from this view — records still exist in the database.':'No records found for this period.')));
      return;
    }
    const days={};
    vis.forEach(t=>{ (days[t.date]=days[t.date]||[]).push(t); });
    Object.keys(days).forEach(date=>{
      const dayWrap=h('div',{});
      dayWrap.appendChild(h('div',{class:'day-hdr'},fmtDate(date)));
      const tl=h('div',{class:'tx-list'});
      days[date].forEach(tx=>{
        const ti=h('div',{class:'tx'});
        ti.appendChild(h('div',{class:`tx-ic ${tx.type}`},AICONS[tx.cat]||'📋'));
        const bd=h('div',{class:'tx-bd'});
        bd.appendChild(h('div',{class:'tx-cat'},tx.cat));
        bd.appendChild(h('div',{class:'tx-dt'},fmtDate(tx.date)));
        if(tx.notes) bd.appendChild(h('div',{class:'tx-nt'},tx.notes));
        ti.appendChild(bd);
        ti.appendChild(h('div',{class:`tx-am ${tx.type}`},(tx.type==='income'?'+':'-')+fmt(tx.amount)));
        tl.appendChild(ti);
      });
      dayWrap.appendChild(tl);
      content.appendChild(dayWrap);
    });
  }

  load();
  return pg;
}

// ─── ADMIN: CHANGE ADMIN PASSWORD ─────────────────────────────────────────────
function buildAdminPassword(){
  const pg=h('div',{class:'page fade'});
  const phdr=h('div',{class:'page-hdr'});
  const bb=h('button',{class:'back-btn'},h('i',{class:'fas fa-chevron-left'}));
  bb.addEventListener('click',()=>{ settingsPage=null; render(); });
  phdr.appendChild(bb);
  phdr.appendChild(h('h2',{},'Change Admin Password'));
  pg.appendChild(phdr);

  let cur='',np='',cp='';
  const fc=h('div',{class:'fc'});
  const errD=h('div',{}); fc.appendChild(errD);
  const ci=h('input',{class:'inp',type:'password',placeholder:'Current password'}); ci.addEventListener('input',e=>cur=e.target.value);
  const ni=h('input',{class:'inp',type:'password',placeholder:'Min. 8 characters'}); ni.addEventListener('input',e=>np=e.target.value);
  const cfi=h('input',{class:'inp',type:'password',placeholder:'Repeat new password'}); cfi.addEventListener('input',e=>cp=e.target.value);
  fc.appendChild(h('div',{class:'fg'},h('label',{},'Current Password'),h('div',{class:'iw'},h('i',{class:'fas fa-lock ic'}),ci)));
  fc.appendChild(h('div',{class:'fg'},h('label',{},'New Password'),h('div',{class:'iw'},h('i',{class:'fas fa-lock ic'}),ni)));
  fc.appendChild(h('div',{class:'fg'},h('label',{},'Confirm New Password'),h('div',{class:'iw'},h('i',{class:'fas fa-lock ic'}),cfi)));
  const sb=h('button',{class:'btn btn-p'},h('i',{class:'fas fa-key'}),' Change Password');
  sb.addEventListener('click',async ()=>{
    if(!cur||!np||!cp){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' All fields are required.')); return; }
    if(np.length<8){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Password must be at least 8 characters.')); return; }
    if(np!==cp){ errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Passwords do not match.')); return; }
    sb.disabled=true;
    try {
      const d = await api('api/profile.php', { method:'PUT', body: JSON.stringify({ action:'password', current:cur, new:np }) });
      errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-s'},h('i',{class:'fas fa-circle-check'}),' Password changed!'));
      ci.value=''; ni.value=''; cfi.value=''; cur=np=cp=''; sb.disabled=false;
    } catch (err) {
      errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' '+err.message));
      sb.disabled=false;
    }
  });
  fc.appendChild(sb);
  pg.appendChild(fc);
  return pg;
}

// ─── SALARY MANAGEMENT────────────────────────────────────────────────────────
function buildSalaryManagement(){
  const pg=h('div',{class:'page fade'});
  const phdr=h('div',{class:'page-hdr'});
  const bb=h('button',{class:'back-btn'},h('i',{class:'fas fa-chevron-left'}));
  bb.addEventListener('click',()=>{ settingsPage=null; render(); });
  phdr.appendChild(bb);
  phdr.appendChild(h('h2',{},'Salary Management'));
  pg.appendChild(phdr);

  const employees=state.employees;

  if(!employees.length){
    pg.appendChild(h('div',{class:'empty'},h('div',{class:'eic'},'👥'),h('p',{},'No employees found.')));
    return pg;
  }

  employees.forEach(emp=>{
    const cur=emp.amount!=null;
    const card=h('div',{class:'emp-card'});
    const av=h('div',{class:'emp-avatar'},emp.name.charAt(0).toUpperCase());
    card.appendChild(av);
    const info=h('div',{class:'emp-info'});
    info.appendChild(h('div',{class:'emp-name'},emp.name));
    info.appendChild(h('div',{class:'emp-email'},emp.email));
    if(cur){
      info.appendChild(h('div',{class:'emp-salary-badge'},'💰 '+fmt(emp.amount)+' · '+MONTH_SHORT[emp.month-1]+' '+emp.year));
    } else {
      info.appendChild(h('div',{class:'emp-salary-badge',style:'background:#fef2f2;color:#dc2626'},'No salary set'));
    }
    card.appendChild(info);
    const actions=h('div',{class:'emp-actions'});
    const editBtn=h('button',{class:'btn btn-p btn-xs'},h('i',{class:'fas fa-edit'}));
    editBtn.title='Set/Edit Salary';
    editBtn.addEventListener('click',()=>{ showSalaryModal(emp); });
    actions.appendChild(editBtn);
    card.appendChild(actions);
    pg.appendChild(card);
  });

  return pg;
}

function showSalaryModal(emp){
  const old=document.querySelector('.modal-overlay');
  if(old) old.remove();

  const cur=(emp.amount!=null)?{amount:emp.amount,month:emp.month,year:emp.year}:null;
  const now=new Date();
  let selMonth=cur?cur.month:now.getMonth()+1;
  let selYear=cur?cur.year:now.getFullYear();
  let newAmt=cur?cur.amount:'';

  const overlay=h('div',{class:'modal-overlay'});
  const sheet=h('div',{class:'modal-sheet'});
  sheet.appendChild(h('div',{class:'modal-handle'}));
  sheet.appendChild(h('div',{class:'modal-title'},'💰 Set Salary — '+emp.name));

  if(cur){
    const curBox=h('div',{class:'salary-current-box'});
    curBox.appendChild(h('div',{class:'sci'},h('i',{class:'fas fa-wallet'})));
    const ct=h('div',{});
    ct.appendChild(h('div',{class:'sct'},'CURRENT SALARY'));
    ct.appendChild(h('div',{class:'scv'},fmt(cur.amount)+' · '+MONTHS[cur.month-1]+' '+cur.year));
    curBox.appendChild(ct);
    sheet.appendChild(curBox);
  }

  const errD=h('div',{}); sheet.appendChild(errD);

  const monthSel=h('select',{class:'inp inp-bare'});
  MONTHS.forEach((m,i)=>{
    const opt=h('option',{value:i+1},m);
    if(i+1===selMonth) opt.selected=true;
    monthSel.appendChild(opt);
  });
  monthSel.addEventListener('change',e=>selMonth=parseInt(e.target.value));
  sheet.appendChild(h('div',{class:'fg'},h('label',{},'Effective Month'),h('div',{class:'iw'},h('i',{class:'fas fa-calendar-days ic'}),monthSel)));

  const yearSel=h('select',{class:'inp inp-bare'});
  for(let y=2023;y<=2028;y++){
    const opt=h('option',{value:y},y);
    if(y===selYear) opt.selected=true;
    yearSel.appendChild(opt);
  }
  yearSel.addEventListener('change',e=>selYear=parseInt(e.target.value));
  sheet.appendChild(h('div',{class:'fg'},h('label',{},'Effective Year'),h('div',{class:'iw'},h('i',{class:'fas fa-calendar ic'}),yearSel)));

  const amtInp=h('input',{class:'inp inp-bare',type:'number',placeholder:'Enter salary amount',min:'1',step:'1',value:newAmt||''});
  amtInp.addEventListener('input',e=>newAmt=e.target.value);
  sheet.appendChild(h('div',{class:'fg'},h('label',{},'Salary Amount (₹)'),h('div',{class:'iw'},h('i',{class:'fas fa-indian-rupee-sign ic'}),amtInp)));

  const saveBtn=h('button',{class:'btn btn-p'},h('i',{class:'fas fa-save'}),' Save Salary');
  saveBtn.addEventListener('click',async ()=>{
    const amt=parseFloat(newAmt);
    if(!newAmt||isNaN(amt)||amt<=0){
      errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please enter a valid salary amount.')); return;
    }
    const existingHistory=state.history.find(r=>r.userId===emp.id&&r.month===selMonth&&r.year===selYear);
    if(existingHistory){
      errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-w'},h('i',{class:'fas fa-triangle-exclamation'}),` A salary record already exists for ${emp.name} in ${MONTHS[selMonth-1]} ${selYear}. Updating it will create a new history entry.`));
    }
    saveBtn.disabled=true;
    try {
      await api('api/salary.php', { method:'POST', body: JSON.stringify({ action:'set', userId:emp.id, amount:amt, month:selMonth, year:selYear }) });
      overlay.remove();
      await refreshAll();
      setPage('settings'); settingsPage='salary-management'; render();
      toast('Salary updated for '+emp.name+'!');
    } catch (err) {
      errD.innerHTML=''; errD.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' '+err.message));
      saveBtn.disabled=false;
    }
  });

  const cancelBtn=h('button',{class:'btn',style:'background:var(--p5);color:var(--mid);margin-top:8px'},h('i',{class:'fas fa-times'}),' Cancel');
  cancelBtn.addEventListener('click',()=>overlay.remove());

  sheet.appendChild(saveBtn);
  sheet.appendChild(cancelBtn);
  overlay.appendChild(sheet);
  overlay.addEventListener('click',e=>{ if(e.target===overlay) overlay.remove(); });
  document.body.appendChild(overlay);
}

// ─── SALARY HISTORY ───────────────────────────────────────────────────────────
function buildSalaryHistory(){
  const pg=h('div',{class:'page fade'});
  const phdr=h('div',{class:'page-hdr'});
  const bb=h('button',{class:'back-btn'},h('i',{class:'fas fa-chevron-left'}));
  bb.addEventListener('click',()=>{ settingsPage=null; render(); });
  phdr.appendChild(bb);
  phdr.appendChild(h('h2',{},'Salary History'));
  pg.appendChild(phdr);

  let filterEmp='all', filterMonth='all', filterYear='all';

  const filterRow=h('div',{class:'filter-row'});

  const empSel=h('select',{});
  empSel.appendChild(h('option',{value:'all'},'All Employees'));
  state.employees.forEach(u=>{
    empSel.appendChild(h('option',{value:u.id},u.name.split(' ')[0]));
  });
  empSel.addEventListener('change',e=>{ filterEmp=e.target.value; refreshList(); });

  const monSel=h('select',{});
  monSel.appendChild(h('option',{value:'all'},'All Months'));
  MONTHS.forEach((m,i)=>monSel.appendChild(h('option',{value:i+1},MONTH_SHORT[i])));
  monSel.addEventListener('change',e=>{ filterMonth=e.target.value; refreshList(); });

  const yrSel=h('select',{});
  yrSel.appendChild(h('option',{value:'all'},'All Years'));
  (state.years.length?state.years:[...new Set(state.history.map(r=>r.year))].sort((a,b)=>b-a)).forEach(y=>yrSel.appendChild(h('option',{value:y},y)));
  yrSel.addEventListener('change',e=>{ filterYear=e.target.value; refreshList(); });

  filterRow.appendChild(empSel);
  filterRow.appendChild(monSel);
  filterRow.appendChild(yrSel);
  pg.appendChild(filterRow);

  const listContainer=h('div',{});
  pg.appendChild(listContainer);

  function refreshList(){
    listContainer.innerHTML='';
    let filtered=[...state.history];
    if(filterEmp!=='all') filtered=filtered.filter(r=>r.userId===parseInt(filterEmp));
    if(filterMonth!=='all') filtered=filtered.filter(r=>r.month===parseInt(filterMonth));
    if(filterYear!=='all') filtered=filtered.filter(r=>r.year===parseInt(filterYear));
    filtered.sort((a,b)=>new Date(b.updatedAt)-new Date(a.updatedAt));

    if(!filtered.length){
      listContainer.appendChild(h('div',{class:'empty'},h('div',{class:'eic'},'📂'),h('p',{},'No salary history found\nfor the selected filters.')));
      return;
    }

    listContainer.appendChild(h('div',{style:'font-size:11px;color:var(--light);font-weight:700;margin-bottom:10px'},`${filtered.length} record${filtered.length!==1?'s':''} found`));

    filtered.forEach(rec=>{
      const card=h('div',{class:'history-card'});
      const hdr=h('div',{class:'history-header'});
      hdr.appendChild(h('span',{class:'history-emp'},rec.employeeName));
      hdr.appendChild(h('span',{class:'history-period'},MONTH_SHORT[rec.month-1]+' '+rec.year));
      card.appendChild(hdr);
      const amounts=h('div',{class:'history-amounts'});
      amounts.appendChild(h('span',{class:'history-old'},fmt(rec.oldSalary)));
      amounts.appendChild(h('span',{class:'history-arrow'},'→'));
      amounts.appendChild(h('span',{class:'history-new'},fmt(rec.newSalary)));
      const diff=rec.newSalary-rec.oldSalary;
      const diffSpan=h('span',{style:`font-size:11px;font-weight:700;margin-left:6px;color:${diff>=0?'#16a34a':'#dc2626'}`},(diff>=0?'+':'')+fmt(diff));
      amounts.appendChild(diffSpan);
      card.appendChild(amounts);
      card.appendChild(h('div',{class:'history-meta'},h('i',{class:'fas fa-clock',style:'margin-right:4px'}),fmtDateTime(rec.updatedAt)));
      listContainer.appendChild(card);
    });
  }

  refreshList();
  return pg;
}

// ─── EVENT EXPENSE MODULE ────────────────────────────────────────────────────
// Refetch the events summary list (respecting the current filters) and re-render.
async function refreshEventsList(){
  const q = new URLSearchParams();
  if(eventFilters.type) q.set('type', eventFilters.type);
  if(eventFilters.name) q.set('name', eventFilters.name);
  if(eventFilters.month) q.set('month', eventFilters.month);
  if(eventFilters.year) q.set('year', eventFilters.year);
  try{
    const d = await api('api/events.php?action=list&'+q.toString());
    state.events = d.events||[];
    state.eventYears = d.availableYears||[];
  }catch(e){ toast(e.message); }
  render();
}

function buildEvents(){
  if(eventSub==='create'||eventSub==='edit') return buildEventForm();
  if(eventSub==='detail') return buildEventDetail();
  return buildEventsList();
}

function buildEventsList(){
  const pg = h('div',{class:'page fade'});
  const phdr = h('div',{class:'page-hdr'});
  const bb = h('button',{class:'back-btn'},h('i',{class:'fas fa-chevron-left'}));
  bb.addEventListener('click',()=>{ setPage('dashboard'); render(); });
  phdr.appendChild(bb); phdr.appendChild(h('h2',{},'Event Expenses'));
  pg.appendChild(phdr);

  const createBtn = h('button',{class:'btn btn-p'},h('i',{class:'fas fa-plus'}),' Create New Event');
  createBtn.addEventListener('click',()=>{ eventSub='create'; activeEvent=null; render(); });
  pg.appendChild(createBtn);

  // Filters
  const f = h('div',{class:'filter-row'});
  const typeSel = h('select',{});
  typeSel.appendChild(h('option',{value:''},'All Types'));
  EVENT_TYPES.forEach(t=>typeSel.appendChild(h('option',{value:t},t)));
  typeSel.value = eventFilters.type;
  typeSel.addEventListener('change',e=>{ eventFilters.type=e.target.value; refreshEventsList(); });

  const yrSel = h('select',{});
  yrSel.appendChild(h('option',{value:''},'All Years'));
  (state.eventYears.length?state.eventYears:[new Date().getFullYear()]).forEach(y=>yrSel.appendChild(h('option',{value:y},String(y))));
  yrSel.value = eventFilters.year ? String(eventFilters.year) : '';
  yrSel.addEventListener('change',e=>{ eventFilters.year=e.target.value; refreshEventsList(); });

  const moSel = h('select',{});
  moSel.appendChild(h('option',{value:''},'All Months'));
  MONTHS.forEach((m,i)=>moSel.appendChild(h('option',{value:String(i+1)},m)));
  moSel.value = eventFilters.month ? String(eventFilters.month) : '';
  moSel.addEventListener('change',e=>{ eventFilters.month=e.target.value; refreshEventsList(); });

  f.appendChild(typeSel); f.appendChild(yrSel); f.appendChild(moSel);
  pg.appendChild(f);

  const nameRow = h('div',{class:'search-row'});
  const nameInp = h('input',{class:'inp inp-bare',type:'text',placeholder:'Search event name…',value:eventFilters.name||''});
  nameInp.addEventListener('keydown',e=>{
    if(e.key==='Enter'){ eventFilters.name=nameInp.value.trim(); refreshEventsList(); }
  });
  nameRow.appendChild(h('div',{class:'iw'},h('i',{class:'fas fa-magnifying-glass ic'}),nameInp));
  pg.appendChild(nameRow);

  const list = h('div',{class:'tx-list'});
  if(!state.events.length){
    list.appendChild(h('div',{class:'empty'},h('div',{class:'eic'},'🎉'),h('p',{},'No events found. Create your first event to start tracking expenses!')));
  } else {
    state.events.forEach(ev=>{
      const card = h('div',{class:'event-card fade'});
      card.appendChild(h('div',{class:'event-card-top'},
        h('div',{class:'event-name'},'🎉 '+ev.event_name),
        h('div',{class:'event-type'},ev.event_type)
      ));
      card.appendChild(h('div',{class:'event-meta'},h('span',{},'Date: '+(ev.event_date?fmtDate(ev.event_date):'—')),h('span',{},'Expenses: '+ev.expense_count)));
      card.appendChild(h('div',{class:`event-total${(ev.budget!==null && ev.total_expense>ev.budget)?' over':''}`},'Total: '+fmt(ev.total_expense)));
      if(ev.budget!==null){
        const rem = ev.total_expense > ev.budget ? 'Budget exceeded by '+fmt(ev.total_expense-ev.budget) : 'Remaining: '+fmt(ev.budget-ev.total_expense);
        card.appendChild(h('div',{class:'event-budget'},rem));
      }
      const acts = h('div',{class:'event-acts'});
      acts.appendChild(h('button',{class:'btn btn-sm btn-p'},h('i',{class:'fas fa-eye'}),' View Details'));
      acts.appendChild(h('button',{class:'btn btn-sm ev-edit'},h('i',{class:'fas fa-pen'}),' Edit'));
      acts.appendChild(h('button',{class:'btn btn-sm ev-del'},h('i',{class:'fas fa-trash'}),' Delete'));
      acts.appendChild(h('button',{class:'btn btn-sm ev-pdf'},h('i',{class:'fas fa-file-pdf'}),' Download PDF'));
      const buttons = acts.querySelectorAll('button');
      buttons[0].addEventListener('click',()=>openEvent(ev.id));
      buttons[1].addEventListener('click',()=>{ activeEvent=null; activeEventId=ev.id; eventSub='edit'; render(); });
      buttons[2].addEventListener('click',()=>deleteEvent(ev.id));
      buttons[3].addEventListener('click',()=>loadEventForPdf(ev.id));
      card.appendChild(acts);
      list.appendChild(card);
    });
  }
  pg.appendChild(list);
  return pg;
}

async function openEvent(id){
  eventSub='detail'; activeEventId=id; activeEvent=null; render();
  try{
    const d = await api('api/events.php?action=get&id='+id);
    activeEvent = d;
    eventSub='detail'; render();
  }catch(e){ toast(e.message); }
}

async function loadEventForPdf(id){
  try{
    const d = await api('api/events.php?action=get&id='+id);
    downloadEventPdf(d);
  }catch(e){ toast(e.message); }
}

function deleteEvent(id){
  const overlay = h('div',{class:'modal-overlay'});
  const sheet = h('div',{class:'modal-sheet'});
  sheet.appendChild(h('div',{class:'modal-handle'}));
  sheet.appendChild(h('div',{class:'modal-title'},'🗑 Delete Event'));
  sheet.appendChild(h('div',{class:'al al-w'},h('i',{class:'fas fa-triangle-exclamation'}),'Delete this event and all its expenses? This cannot be undone.'));
  const del = h('button',{class:'btn btn-d'},h('i',{class:'fas fa-trash'}),' Delete Event');
  del.addEventListener('click',async ()=>{
    del.disabled=true;
    try{
      await api('api/events.php',{method:'POST',body:JSON.stringify({action:'delete',id})});
      await refreshAll();
      toast('Event deleted successfully!');
      overlay.remove();
      if(eventSub==='detail'&&activeEventId===id){ eventSub='list'; activeEvent=null; activeEventId=null; }
      await refreshEventsList();
    }catch(e){ toast(e.message); }
  });
  const cancel = h('button',{class:'btn',style:'background:var(--p5);color:var(--mid);margin-top:10px'},h('i',{class:'fas fa-times'}),' Cancel');
  cancel.addEventListener('click',()=>overlay.remove());
  sheet.appendChild(del); sheet.appendChild(cancel);
  overlay.appendChild(sheet);
  overlay.addEventListener('click',e=>{ if(e.target===overlay) overlay.remove(); });
  document.body.appendChild(overlay);
}

function buildEventForm(){
  const editing = eventSub==='edit';
  const ev = editing ? (state.events.find(x=>x.id===activeEventId)||{}) : {};
  let name = ev.event_name||'', type = ev.event_type||'', date = ev.event_date||toDay(), location = ev.location||'', description = ev.description||'', budget = ev.budget!=null?String(ev.budget):'';
  let customOther = '';
  if(type && !EVENT_TYPES.includes(type)){ customOther = type; type = 'Other'; } // show custom type as "Other" + a custom value
  const pg = h('div',{class:'page fade'});
  const phdr = h('div',{class:'page-hdr'});
  const bb = h('button',{class:'back-btn'},h('i',{class:'fas fa-chevron-left'}));
  bb.addEventListener('click',()=>{ eventSub='list'; render(); refreshEventsList(); });
  phdr.appendChild(bb); phdr.appendChild(h('h2',{},editing?'Edit Event':'Create New Event'));
  pg.appendChild(phdr);
  const errDiv = h('div',{}); pg.appendChild(errDiv);

  const fc = h('div',{class:'fc'});
  const nameInp = h('input',{class:'inp inp-bare',type:'text',placeholder:'e.g. My Sister Marriage',value:name});
  nameInp.addEventListener('input',e=>{ name=e.target.value; });
  fc.appendChild(h('div',{class:'fg'},h('label',{},'Event Name *'),h('div',{class:'iw'},h('i',{class:'fas fa-bullhorn ic'}),nameInp)));

  const typeSel = h('select',{class:'inp inp-bare'});
  typeSel.appendChild(h('option',{value:''},'Select type…'));
  EVENT_TYPES.forEach(t=>typeSel.appendChild(h('option',{value:t},t)));
  typeSel.value = type;
  const otherInp = h('input',{class:'inp inp-bare',type:'text',placeholder:'Enter your custom event type'});
  otherInp.value = customOther;
  otherInp.addEventListener('input',e=>{ customOther=e.target.value; });
  const otherFg = h('div',{class:'fg',style:type==='Other'?'':'display:none'},h('label',{},'Custom Event Type *'),h('div',{class:'iw'},h('i',{class:'fas fa-pen ic'}),otherInp));
  typeSel.addEventListener('change',e=>{
    type = e.target.value;
    otherFg.style.display = type==='Other' ? '' : 'none';
    if(type==='Other') otherInp.focus();
  });
  fc.appendChild(h('div',{class:'fg'},h('label',{},'Event Type *'),h('div',{class:'iw'},h('i',{class:'fas fa-tags ic'}),typeSel)));
  fc.appendChild(otherFg);

  const dateInp = h('input',{class:'inp',type:'date',value:date});
  dateInp.addEventListener('change',e=>date=e.target.value);
  fc.appendChild(h('div',{class:'fg'},h('label',{},'Event Date *'),h('div',{class:'iw'},h('i',{class:'fas fa-calendar ic'}),dateInp)));

  const locInp = h('input',{class:'inp inp-bare',type:'text',placeholder:'e.g. Chennai',value:location});
  locInp.addEventListener('input',e=>{ location=e.target.value; });
  fc.appendChild(h('div',{class:'fg'},h('label',{},'Event Location'),h('div',{class:'iw'},h('i',{class:'fas fa-location-dot ic'}),locInp)));

  const budgetInp = h('input',{class:'inp inp-bare',type:'number',inputmode:'decimal',min:'0',step:'0.01',placeholder:'Optional budget',value:budget});
  budgetInp.addEventListener('input',e=>budget=e.target.value);
  fc.appendChild(h('div',{class:'fg'},h('label',{},'Event Budget (',h('span',{style:'color:var(--light);font-weight:400'},'optional'),')'),h('div',{class:'iw'},h('i',{class:'fas fa-money-bill-wave ic'}),budgetInp)));

  const descInp = h('textarea',{class:'inp',placeholder:'Event description / notes…',})
  descInp.value = description;
  descInp.addEventListener('input',e=>{ description=e.target.value; });
  fc.appendChild(h('div',{class:'fg'},h('label',{},'Event Description / Notes'),h('div',{class:'iw',style:'position:relative'},h('i',{class:'fas fa-note-sticky ic',style:'top:17px;transform:none'}),descInp)));

  const save = h('button',{class:'btn btn-p'},h('i',{class:'fas fa-save'}), editing?' Save Changes':' Create Event');
  save.addEventListener('click',async ()=>{
    const resolvedType = type==='Other' ? (customOther.trim() || 'Other') : type;
    if(!name.trim()){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please enter the event name.')); return; }
    if(!resolvedType){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please select an event type.')); return; }
    if(type==='Other'&&!customOther.trim()){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please enter your custom event type.')); return; }
    if(!date){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please select the event date.')); return; }
    const b = budget!=='' ? parseFloat(budget) : null;
    if(b!==null&&(isNaN(b)||b<=0)){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please enter a valid budget.')); return; }
    save.disabled=true;
    try{
      // Toggle now lives in the Add Expense section; preserve the event's stored
      // setting here (new events default to English, editing keeps its value).
      const payload = { action: editing?'update':'create', event_name:name.trim(), event_type:resolvedType, event_date:date, location, description, budget:b, enable_tanglish: editing ? (ev.enable_tanglish===true) : false };
      if(editing) payload.id = activeEventId;
      await api('api/events.php',{method:'POST',body:JSON.stringify(payload)});
      toast(editing?'Event updated successfully!':'Event created successfully!');
      await refreshAll();
      eventSub='list'; activeEventId=null; activeEvent=null;
      await refreshEventsList();
    }catch(e){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' '+e.message)); save.disabled=false; }
  });
  fc.appendChild(save);
  pg.appendChild(fc);
  return pg;
}

function buildEventDetail(){
  const pg = h('div',{class:'page fade'});
  const phdr = h('div',{class:'page-hdr'});
  const bb = h('button',{class:'back-btn'},h('i',{class:'fas fa-chevron-left'}));
  bb.addEventListener('click',()=>{ eventSub='list'; activeEvent=null; render(); refreshEventsList(); });
  phdr.appendChild(bb); phdr.appendChild(h('h2',{},'Event Details'));
  pg.appendChild(phdr);
  if(!activeEvent){ pg.appendChild(h('div',{class:'empty'},h('div',{class:'eic'},'📭'),h('p',{},'Loading…'))); return pg; }

  const ev = activeEvent.event||{};
  const total = activeEvent.total||0;
  const nb = total - (ev.budget||0);

  const card = h('div',{class:'event-detail'});
  card.appendChild(h('div',{class:'event-detail-name'},'🎉 '+ev.event_name));
  card.appendChild(h('div',{class:'event-type'},ev.event_type));
  const rows = [
    ev.event_date?['Date',fmtDate(ev.event_date)]:null,
    ev.location?['Location',ev.location]:null,
    ['Expenses',activeEvent.count+' item'+(activeEvent.count===1?'':'s')],
  ];
  rows.forEach(r=>{ if(r) card.appendChild(h('div',{class:'event-detail-row'},h('span',{},r[0]),h('span',{},r[1]))); });
  card.appendChild(h('div',{class:'event-total'},'Total Expense: '+fmt(total)));
  if(ev.budget!==null){
    card.appendChild(h('div',{class:`event-budget ${nb>0?'over':''}`},nb>0?'Budget exceeded by '+fmt(nb):'Budget: '+fmt(ev.budget)+' · Remaining: '+fmt(ev.budget-total)));
  }
  if(ev.description) card.appendChild(h('div',{class:'event-desc'},ev.description));
  pg.appendChild(card);

  const addExp = h('button',{class:'btn btn-p'},h('i',{class:'fas fa-plus'}),' Add Expense');
  addExp.addEventListener('click',()=>openEventExpenseModal(null));
  const pdfBtn = h('button',{class:'btn ev-pdf'},h('i',{class:'fas fa-file-pdf'}),' Download Event PDF');
  pdfBtn.addEventListener('click',()=>downloadEventPdf(activeEvent));
  const edBtn = h('button',{class:'btn ev-edit'},h('i',{class:'fas fa-pen'}),' Edit Event');
  edBtn.addEventListener('click',()=>{ eventSub='edit'; render(); });
  const acts = h('div',{class:'event-detail-acts'});
  acts.appendChild(addExp); acts.appendChild(pdfBtn); acts.appendChild(edBtn);
  pg.appendChild(acts);

  // Expense analysis
  if(Object.keys(activeEvent.itemTotals||{}).length){
    const anl = h('div',{class:'card'});
    anl.appendChild(h('h3',{class:'sh'},'Where the money went'));
    Object.keys(activeEvent.itemTotals).slice(0,12).forEach(k=>{
      const pct = activeEvent.total>0?Math.round(activeEvent.itemTotals[k]/activeEvent.total*100):0;
      const row = h('div',{class:'cat-row'});
      row.appendChild(h('div',{class:'cln',style:'flex:1'},k));
      row.appendChild(h('div',{class:'cln',style:'flex:0 0 auto;font-weight:800;color:#dc2626'},fmt(activeEvent.itemTotals[k])));
      const barWrap = h('div',{class:'cbw'});
      barWrap.appendChild(h('div',{class:'cb',style:'width:'+pct+'%'}));
      row.appendChild(barWrap);
      row.appendChild(h('div',{class:'cpct'},pct+'%'));
      anl.appendChild(row);
    });
    pg.appendChild(anl);
  }

  // Payment summary
  if(activeEvent.paymentTotals && Object.keys(activeEvent.paymentTotals).length){
    const ps = h('div',{class:'card'});
    ps.appendChild(h('h3',{class:'sh'},'Payment Summary'));
    Object.keys(activeEvent.paymentTotals).forEach(k=>{
      ps.appendChild(h('div',{class:'event-detail-row'},h('span',{},k),h('span',{},fmt(activeEvent.paymentTotals[k]))));
    });
    pg.appendChild(ps);
  }

  const tl = h('div',{class:'tx-list'});
  pg.appendChild(h('div',{class:'sh'},h('h3',{},'Expense Items'),h('div',{class:'sh-sub'},activeEvent.count+' items')));

  if(!activeEvent.expenses.length){
    tl.appendChild(h('div',{class:'empty'},h('div',{class:'eic'},'🧾'),h('p',{},'No expenses yet. Tap “Add Expense” to record your first event expense.')));
  } else {
    activeEvent.expenses.forEach((x,i)=>{
      const item = h('div',{class:'tx'});
      item.appendChild(h('div',{class:'tx-ic expense'},'🧾'));
      const bd = h('div',{class:'tx-bd'});
      bd.appendChild(h('div',{class:'tx-cat'},x.expense_item));
      const meta=[];
      if(x.paid_to) meta.push('Paid to: '+x.paid_to);
      if(x.payment_method) meta.push(x.payment_method);
      if(x.expense_date) meta.push(fmtDate(x.expense_date)+(x.expense_time?' · '+fmtTime12(x.expense_time):''));
      bd.appendChild(h('div',{class:'tx-sub'},meta.join(' — ')));
      if(x.notes) bd.appendChild(h('div',{class:'tx-nt'},x.notes));
      item.appendChild(bd);
      item.appendChild(h('div',{class:'tx-am expense'},fmt(x.amount)));
      const actsCol = h('div',{class:'tx-acts'});
      const eb = h('button',{class:'tx-act',title:'Edit'},h('i',{class:'fas fa-pen'}));
      eb.addEventListener('click',()=>openEventExpenseModal(x));
      const db = h('button',{class:'tx-act del',title:'Delete'},h('i',{class:'fas fa-trash'}));
      db.addEventListener('click',()=>deleteExpense(x.id));
      actsCol.appendChild(eb); actsCol.appendChild(db);
      item.appendChild(actsCol);
      tl.appendChild(item);
    });
  }
  pg.appendChild(tl);
  return pg;
}

function openEventExpenseModal(expense){
  const overlay = h('div',{class:'modal-overlay'});
  const sheet = h('div',{class:'modal-sheet'});
  sheet.appendChild(h('div',{class:'modal-handle'}));
  sheet.appendChild(h('div',{class:'modal-title'},expense?'✏️ Edit Expense':'＋ Add Expense'));
  const errDiv = h('div',{}); sheet.appendChild(errDiv);

  // ── Tanglish → Tamil toggle (per-event, persisted). ON = PDF/expenses in Tamil
  let tglOn = !!(activeEvent && activeEvent.event && activeEvent.event.enable_tanglish);
  let convertTimer = null;
  const tglWrap = h('div',{class:'tgl-card'});
  const tglInfo = h('div',{class:'tgl-info'});
  tglInfo.appendChild(h('div',{class:'tgl-title'},'Tanglish → Tamil'));
  tglInfo.appendChild(h('div',{class:'tgl-sub'},'Expenses & PDF in Tamil when ON'));
  const tglBtn = h('label',{class:'switch'});
  const tglChk = h('input',{type:'checkbox'});
  tglChk.checked = tglOn;
  const tglSpan = h('span',{class:'slider'});
  tglBtn.appendChild(tglChk); tglBtn.appendChild(tglSpan);
  const tglStatus = h('div',{class:'tgl-status'+(tglOn?' on':'')}, tglOn?'ON – Tamil':'OFF – English');
  const tglConvBtn = h('button',{type:'button',class:'btn btn-sm tgl-conv',style:tglOn?'':'display:none'},h('i',{class:'fas fa-language'}),' Convert now');
  const updateTglUI = ()=>{
    tglChk.checked = tglOn;
    tglStatus.className = 'tgl-status'+(tglOn?' on':'');
    tglStatus.textContent = tglOn ? 'ON – Tamil' : 'OFF – English';
    tglConvBtn.style.display = tglOn ? '' : 'none';
  };
  const persistTgl = async ()=>{
    const ev = (activeEvent&&activeEvent.event)||{};
    try{
      await api('api/events.php',{method:'POST',body:JSON.stringify({
        action:'update', id: activeEventId,
        event_name: ev.event_name||'', event_type: ev.event_type||'', event_date: ev.event_date||'',
        location: ev.location||null, description: ev.description||null, budget: ev.budget!=null?ev.budget:null,
        enable_tanglish: tglOn
      })});
      if(activeEvent&&activeEvent.event) activeEvent.event.enable_tanglish = tglOn;
    }catch(e){ toast(e.message); }
  };
  tglChk.addEventListener('change',e=>{
    tglOn = e.target.checked; updateTglUI(); persistTgl();
    if(tglOn) runConvertNow();
  });
  tglConvBtn.addEventListener('click',()=>runConvertNow(true));
  const tglRight = h('div',{class:'tgl-right'});
  tglRight.appendChild(tglBtn); tglRight.appendChild(tglStatus);
  tglWrap.appendChild(tglInfo); tglWrap.appendChild(tglRight);
  sheet.appendChild(tglWrap);
  sheet.appendChild(h('div',{class:'tgl-conv-row'},tglConvBtn));

  const now = new Date();
  let item = expense?expense.expense_item:'', amount = expense?String(expense.amount):'';
  let paidTo = expense?expense.paid_to:'', method = expense?expense.payment_method:'Cash';
  let expDate = expense?(expense.expense_date||toDay()):toDay();
  let expTime = expense?(expense.expense_time?String(expense.expense_time).slice(0,5):''):(pad2(now.getHours())+':'+pad2(now.getMinutes()));
  let notes = expense?expense.notes:'';

  const fc = h('div',{});
  const itemInp = h('input',{class:'inp inp-bare',type:'text',placeholder:'e.g. Wedding Hall',value:item});
  itemInp.addEventListener('input',e=>{ item=e.target.value; if(tglOn) queueConvert(); });
  fc.appendChild(h('div',{class:'fg'},h('label',{},'What did you spend money on? *'),h('div',{class:'iw'},h('i',{class:'fas fa-cart-shopping ic'}),itemInp)));

  const amtInp = h('input',{class:'inp',type:'number',inputmode:'decimal',min:'0.01',step:'0.01',placeholder:'0.00',value:amount});
  amtInp.addEventListener('input',e=>amount=e.target.value);
  fc.appendChild(h('div',{class:'fg'},h('label',{},'Amount *'),h('div',{class:'iw'},h('i',{class:'fas fa-indian-rupee-sign ic'}),amtInp)));

  const methodSel = h('select',{class:'inp inp-bare'});
  EVENT_PAY_METHODS.forEach(m=>methodSel.appendChild(h('option',{value:m},m)));
  methodSel.value = method;
  methodSel.addEventListener('change',e=>method=e.target.value);
  fc.appendChild(h('div',{class:'fg'},h('label',{},'Payment Method'),h('div',{class:'iw'},h('i',{class:'fas fa-credit-card ic'}),methodSel)));

  // Time is no longer entered by hand: a new expense is stamped with the current
  // time (see expTime above) and editing keeps whatever was already saved.
  const dateInp = h('input',{class:'inp',type:'date',value:expDate});
  dateInp.addEventListener('change',e=>expDate=e.target.value);
  fc.appendChild(h('div',{class:'fg'},h('label',{},'Date'),h('div',{class:'iw'},h('i',{class:'fas fa-calendar ic'}),dateInp)));

  const notesInp = h('textarea',{class:'inp',placeholder:'Notes (optional)…',})
  notesInp.value = notes;
  notesInp.addEventListener('input',e=>{ notes=e.target.value; if(tglOn) queueConvert(); });
  fc.appendChild(h('div',{class:'fg'},h('label',{},'Notes'),h('div',{class:'iw-notes'},notesInp)));

  let convertGen = 0;
  const applyConvert = async ()=>{
    const gen = ++convertGen;
    const origItem = item, origNotes = notes;
    const newItem = await translateToTamil(origItem);
    if(gen !== convertGen) return; // a newer edit superseded this conversion
    const newNotes = await translateToTamil(origNotes);
    if(gen !== convertGen) return;
    item = newItem; notes = newNotes;
    if(itemInp.value !== item) itemInp.value = item;
    if(notesInp.value !== notes) notesInp.value = notes;
  };
  queueConvert = ()=>{ clearTimeout(convertTimer); convertTimer = setTimeout(()=>{ if(tglOn) applyConvert(); },700); };
  const runConvertNow = (manual)=>{ applyConvert().then(()=>{ if(manual && tglOn) toast('Converted to Tamil'); }); };

  const save = h('button',{class:'btn btn-p'},h('i',{class:'fas fa-save'}),' Save Expense');
  save.addEventListener('click',async ()=>{
    const a = amount!==''?parseFloat(amount):NaN;
    if(!item.trim()){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please enter what you spent the money on.')); return; }
    if(isNaN(a)||a<=0){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Amount must be greater than ₹0.')); return; }
    if(!expDate){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' Please select a date.')); return; }
    save.disabled=true;
    try{
      const evName = (activeEvent && activeEvent.event && activeEvent.event.event_name) ? activeEvent.event.event_name : 'Event';
      if(tglOn){
        convertGen++; // cancel any in-flight live conversion
        item = await translateToTamil(item);
        notes = await translateToTamil(notes);
        const pTo = (paidTo.trim() && paidTo.trim().toLowerCase()!==evName.toLowerCase()) ? paidTo.trim() : (evName||'');
        paidTo = await translateToTamil(pTo);
        if(itemInp.value!==item) itemInp.value = item;
        if(notesInp.value!==notes) notesInp.value = notes;
      }
      const payload = { action: expense?'update_expense':'add_expense', event_id:activeEventId, expense_item:item.trim(), amount:a, paid_to:(paidTo.trim()||evName), payment_method:method, expense_date:expDate, expense_time:expTime||'', notes:notes.trim() };
      if(expense) payload.id = expense.id;
      await api('api/events.php',{method:'POST',body:JSON.stringify(payload)});
      await refreshAll();
      toast(expense?'Expense updated successfully!':'Expense added successfully!');
      overlay.remove();
      await openEvent(activeEventId);
    }catch(e){ errDiv.innerHTML=''; errDiv.appendChild(h('div',{class:'al al-e'},h('i',{class:'fas fa-circle-exclamation'}),' '+e.message)); save.disabled=false; }
  });
  const cancel = h('button',{class:'btn',style:'background:var(--p5);color:var(--mid);margin-top:10px'},h('i',{class:'fas fa-times'}),' Cancel');
  cancel.addEventListener('click',()=>overlay.remove());
  fc.appendChild(save); fc.appendChild(cancel);
  sheet.appendChild(fc);
  overlay.appendChild(sheet);
  overlay.addEventListener('click',e=>{ if(e.target===overlay) overlay.remove(); });
  document.body.appendChild(overlay);
}

function deleteExpense(id){
  const overlay = h('div',{class:'modal-overlay'});
  const sheet = h('div',{class:'modal-sheet'});
  sheet.appendChild(h('div',{class:'modal-handle'}));
  sheet.appendChild(h('div',{class:'modal-title'},'🗑 Delete Expense'));
  sheet.appendChild(h('div',{class:'al al-w'},h('i',{class:'fas fa-triangle-exclamation'}),'Delete this expense? The event total will be recalculated.'));
  const del = h('button',{class:'btn btn-d'},h('i',{class:'fas fa-trash'}),' Delete Expense');
  del.addEventListener('click',async ()=>{
    del.disabled=true;
    try{
      await api('api/events.php',{method:'POST',body:JSON.stringify({action:'delete_expense',id})});
      await refreshAll();
      toast('Expense deleted successfully!');
      overlay.remove();
      await openEvent(activeEventId);
    }catch(e){ toast(e.message); }
  });
  const cancel = h('button',{class:'btn',style:'background:var(--p5);color:var(--mid);margin-top:10px'},h('i',{class:'fas fa-times'}),' Cancel');
  cancel.addEventListener('click',()=>overlay.remove());
  sheet.appendChild(del); sheet.appendChild(cancel);
  overlay.appendChild(sheet);
  overlay.addEventListener('click',e=>{ if(e.target===overlay) overlay.remove(); });
  document.body.appendChild(overlay);
}

function fmtTime12(t){
  if(!t) return '';
  const s=String(t).slice(0,5); const p=s.split(':'); if(p.length<2) return t;
  let h=parseInt(p[0],10)%24, ap=h>=12?'PM':'AM'; h=h%12||12;
  return h+':'+p[1]+' '+ap;
}

// Event PDF (client-side jsPDF, consistent with the existing report builders).
async function downloadEventPdf(d){
  if(!pdfReady()){ toast('PDF library not loaded. Check your internet connection.'); return; }
  await ensureTamilFont(); // embed Unicode Tamil font (best-effort; English still works)
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({unit:'mm',format:'a4'});
  applyTamilFont(doc);
  const W=210, M=14, right=W-M;

  // ── Language switch (per-event toggle): ON → full Tamil PDF, OFF → English
  const ev = d.event||{};
  const tamil = (ev.enable_tanglish===true);
  const T = (en, ta)=> tamil ? ta : en;
  const methodTa = { 'Cash':'பணம்','UPI':'யூபிஐ','GPay':'ஜி-பே','PhonePe':'போன்-பே','Bank Transfer':'வங்கி பரிமாற்றம்','Debit Card':'டெபிட் கார்டு','Credit Card':'கிரெடிட் கார்டு','Other':'மற்றவை' };
  const typeTa = { 'Marriage':'திருமணம்','Birthday':'பிறந்தநாள்','Housewarming':'வீடு கிரகப்பிரவேசம்','Travel':'பயணம்','Festival':'பண்டிகை','Party':'விருந்து','Function':'நிகழ்ச்சி','Medical':'மருத்துவம்','Education':'கல்வி','Shopping':'ஷாப்பிங்','Business':'வணிகம்','Other':'மற்றவை' };

  // Pre-translate every distinct free-text value (event fields + expense items /
  // payees) into proper Tamil when the toggle is ON, so the PDF shows the same
  // translated content that was stored. Already-Tamil text passes through.
  const trans = new Map();
  const freeText = [];
  if(tamil){
    freeText.push(ev.event_name, ev.location, ev.description);
    (d.expenses||[]).forEach(x=>{ freeText.push(x.expense_item, x.paid_to); });
  }
  const unique = [...new Set(freeText.filter(v=>v && /[a-zA-Z]/.test(String(v))).map(v=>String(v)))];
  await Promise.all(unique.map(async s=>{
    try{ const t = await translateToTamil(s); if(t) trans.set(s, t); }catch(e){}
  }));
  const tl = v => tamil ? (trans.get(String(v==null?'':v)) ?? String(v==null?'':v)) : String(v==null?'':v);
  const tlName = v => tamil ? (typeTa[v] ?? tl(String(v==null?'':v))) : String(v==null?'':v);
  const tlMethod = v => tamil ? (methodTa[v] ?? tl(String(v==null?'':v))) : String(v==null?'':v);
  const L = {
    tagline:  T('Personal Expense Manager','தனிப்பட்ட செலவு மேலாளர்'),
    report:   T('EVENT EXPENSE REPORT','நிகழ்வு செலவு அறிக்கை'),
    generated: T('Generated ','உருவாக்கப்பட்டது '),
    details:  T('Event Details','நிகழ்வு விவரங்கள்'),
    name:     T('Event Name','நிகழ்வு பெயர்'),
    type:     T('Event Type','நிகழ்வு வகை'),
    date:     T('Event Date','நிகழ்வு தேதி'),
    loc:      T('Location','இடம்'),
    notes:    T('Notes','குறிப்புகள்'),
    empty:    T('No expenses recorded for this event.','இந்த நிகழ்விற்கு செலவுகள் பதிவு செய்யப்படவில்லை.'),
    total:    T('Total Event Expense','மொத்த நிகழ்வு செலவு'),
    itemWord: T('item','பொருள்'),
    summary:  T('Expense Summary','செலவு சுருக்கம்'),
    totalExp: T('Total Expenses: ','மொத்த செலவுகள்: '),
    cash:     T('Cash: ','பணம்: '),
    upi:      T('UPI: ','யூபிஐ: '),
    numItems: T('Number of Expense Items: ','செலவு பொருட்களின் எண்ணிக்கை: '),
    footer:   T('Event Expense Report','நிகழ்வு செலவு அறிக்கை'),
    h0:T('S.No','வ.எண்'), h1:T('Item','பொருள்'), h2:T('Paid To','யாருக்கு'), h3:T('Amount','தொகை'), h4:T('Method','முறை'), h5:T('Date','தேதி'), h6:T('Time','நேரம்')
  };

  doc.setFillColor(124,58,237); doc.rect(0,0,W,30,'F');
  doc.setFillColor(167,139,250); doc.rect(0,27.5,W,1.5,'F');
  const logo = await pdfLogo();
  let bx = M;
  if(logo){ try{ const bw=17, bh=Math.min(17,bw/logo.ratio); doc.addImage(logo.url,'PNG',M,6.5+(17-bh)/2,bw,bh); }catch(e){} bx=M+21; }
  doc.setTextColor(255,255,255);
  doc.setFont('helvetica','bold'); doc.setFontSize(18);
  doc.text('MONEY WISE', bx, 12.5);
  setPdfFont(doc, L.tagline); doc.setFontSize(9.5); doc.setTextColor(237,233,254);
  doc.text(L.tagline, bx, 18.5);
  doc.setFont('helvetica','bold'); doc.setFontSize(13); doc.setTextColor(255,255,255);
  doc.text(L.report, right, 12.5, {align:'right'});
  doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(237,233,254);
  doc.text(L.generated+new Date().toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}), right, 18.5, {align:'right'});

  let y=38;
  setPdfFontBold(doc, L.details); doc.setFontSize(13); doc.setTextColor(30,27,46);
  doc.text(L.details, M, y); y+=7;
  const info = [
    [L.name, tl(ev.event_name)||'—'],
    [L.type, tlName(ev.event_type)||'—'],
    [L.date, ev.event_date?fmtDate(ev.event_date):'—'],
    [L.loc, tl(ev.location)||'—'],
  ];
  doc.setFont('helvetica','normal'); doc.setFontSize(10);
  info.forEach(r=>{
    setPdfFont(doc, r[0]); doc.setTextColor(107,114,128); doc.text(r[0]+':', M, y);
    setPdfFont(doc, r[1]);
    doc.setTextColor(30,27,46); doc.text(String(r[1]), M+40, y);
    doc.setFont('helvetica','normal'); doc.setFontSize(10);
    y+=5;
  });
  y+=2;

  // Expense table
  // Fixed column-width structure (sums to printable width = W - 2*M):
  //   S.No | Item | Paid To | Amount | Method | Date | Time
  const exps = d.expenses||[];
  const th=7, rh0=6.5, bottom=276, pad=1.5, lh=4.2;
  const colDefs=[
    {w:11, align:'center'},   // S.No
    {w:34, align:'left'},     // Item
    {w:30, align:'left'},     // Paid To
    {w:27, align:'right'},    // Amount
    {w:32, align:'center'},   // Method
    {w:26, align:'center'},   // Date
    {w:22, align:'center'},   // Time
  ];
  const widths=colDefs.map(c=>c.w);
  const xs=[]; let tx=M; widths.forEach(w=>{ xs.push(tx); tx+=w; });
  const tableRight = xs[widths.length-1]+widths[widths.length-1]; // === right (page margin)
  const wrapCell = (text,w)=>{
    setPdfFont(doc, text);
    return doc.splitTextToSize(String(text), w);
  };
  const drawHead=()=>{
    doc.setFillColor(124,58,237); doc.rect(M,y,tableRight-M,th,'F');
    setPdfFontBold(doc, L.h0); doc.setFontSize(8); doc.setTextColor(255,255,255);
    const head=[L.h0,L.h1,L.h2,L.h3,L.h4,L.h5,L.h6];
    head.forEach((h,i)=>{
      const a=colDefs[i].align;
      setPdfFont(doc, h);
      const hx = a==='right' ? xs[i]+widths[i]-pad : a==='center' ? xs[i]+widths[i]/2 : xs[i]+pad;
      doc.text(h,hx,y+4.6, a==='right'?{align:'right'}: a==='center'?{align:'center'}:undefined);
    });
    y+=th;
  };
  drawHead();
  doc.setFont('helvetica','normal'); doc.setFontSize(8);
  if(!exps.length){
    pdfEmptyBox(doc,W,M,y,L.empty);
  } else {
    exps.forEach((r,ri)=>{
      const row=[String(ri+1), tl(r.expense_item)||'', tl(r.paid_to)||'', fmt(r.amount), tlMethod(r.payment_method)||'', r.expense_date?fmtDate(r.expense_date).replace(/\d{2}$/,'').trim():'—', r.expense_time?fmtTime12(r.expense_time):'—'];
      const wrapped = row.map((v,i)=> i===0 ? [v] : wrapCell(v, widths[i]-2*pad));
      const lines = Math.max.apply(null, wrapped.map(a=>a.length));
      const cellH = Math.max(rh0, lines*lh + (lines>1?2:0));
      if(y+cellH>bottom){ doc.addPage(); y=26; drawHead(); }
      const shadeRgb = ri%2 ? [245,243,255] : [255,255,255];
      doc.setFillColor(shadeRgb[0],shadeRgb[1],shadeRgb[2]); doc.rect(M,y,tableRight-M,cellH,'F');
      wrapped.forEach((wl,i)=>{
        const a=colDefs[i].align;
        const baseY = y + (cellH - wl.length*lh)/2 + lh;
        wl.forEach((ln,li)=>{
          const yy = baseY + li*lh;
          const vx = a==='right' ? xs[i]+widths[i]-pad : a==='center' ? xs[i]+widths[i]/2 : xs[i]+pad;
          if(i===0) doc.setTextColor(124,58,237);
          else if(i===3) doc.setTextColor(220,38,38);
          else doc.setTextColor(30,27,46);
          setPdfFont(doc, String(ln));
          doc.text(String(ln),vx,yy, a==='right'?{align:'right'}: a==='center'?{align:'center'}:undefined);
        });
      });
      y+=cellH;
    });
  }
  y+=4;

  // Total
  y = pdfSummaryBox(doc, W, M, y, L.total, pdfMoney(d.total||0), (d.count||0)+' '+L.itemWord+((d.count===1)?'':'s'));
  y+=1;

  // Notes
  if(ev.description){ setPdfFontBold(doc, L.notes); doc.setFontSize(9); doc.setTextColor(124,58,237); doc.text(L.notes, M, y); y+=5; const dlines = doc.splitTextToSize(tl(ev.description), W-2*M); dlines.forEach(l=>{ setPdfFont(doc, l); doc.setFontSize(9); doc.setTextColor(75,85,99); doc.text(l, M, y); y+=4.5; }); y+=1; }

  // Expense summary
  y = pdfSection(doc, W, M, y, L.summary);
  doc.setFont('helvetica','normal'); doc.setFontSize(9);
  const cash = d.paymentTotals&&d.paymentTotals['Cash']?d.paymentTotals['Cash']:0;
  const upi = d.paymentTotals&&d.paymentTotals['UPI']?d.paymentTotals['UPI']:0;
  doc.setTextColor(75,85,99);
  setPdfFont(doc, L.totalExp); doc.text(L.totalExp, M, y); doc.setTextColor(30,27,46); setPdfFontBold(doc, pdfMoney(d.total||0)); doc.text(pdfMoney(d.total||0), right, y, {align:'right'}); y+=5;
  doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(75,85,99);
  setPdfFont(doc, L.cash); doc.text(L.cash, M, y); doc.setTextColor(30,27,46); setPdfFont(doc, pdfMoney(cash)); doc.text(pdfMoney(cash), right, y, {align:'right'}); y+=5;
  setPdfFont(doc, L.upi); doc.text(L.upi, M, y); doc.setTextColor(30,27,46); setPdfFont(doc, pdfMoney(upi)); doc.text(pdfMoney(upi), right, y, {align:'right'}); y+=5;
  setPdfFont(doc, L.numItems); doc.text(L.numItems, M, y); doc.setTextColor(30,27,46); setPdfFont(doc, String(d.count||0)); doc.text(String(d.count||0), right, y, {align:'right'}); y+=5;

  pdfFooter(doc, W, M, L.footer+' · '+tl(ev.event_name));
  doc.save('MoneyWise-Event-Report-'+(ev.event_name||'event').replace(/[^\w\-]+/g,'_')+'.pdf');
  toast('Event PDF downloaded!');
}

// ─── CALCULATOR MODULE ───────────────────────────────────────────────────────
// A simple, normal calculator: + − × ÷, percentages, decimals, +/− sign toggle,
// AC clear, backspace and =. The engine keeps full operator precedence,
// correct decimal handling and explicit error messages. The '=' button only
// evaluates the expression — there is no private storage, PIN vault, password,
// hidden page or media upload anywhere in this module.

const CALC_PREC  = {'+':1,'-':1,'*':2,'/':2,'^':3};
const CALC_RIGHT = ['^'];

function calcTokenize(expr){
  const out=[]; let i=0;
  while(i<expr.length){
    const c=expr[i];
    if(/[0-9.]/.test(c)){
      if(out.length && out[out.length-1].t==='rparen') out.push({t:'op',v:'*'}); // (3)4 → (3)*4
      let j=i; while(j<expr.length && /[0-9.]/.test(expr[j])) j++;
      const raw=expr.slice(i,j);
      if((raw.match(/\./g)||[]).length>1) throw new Error('That number is not valid.');
      out.push({t:'num',v:parseFloat(raw)}); i=j; continue;
    }
    if('+-*/^'.includes(c)){ out.push({t:'op',v:c}); i++; continue; }
    if(c==='('){
      if(out.length && (out[out.length-1].t==='num'||out[out.length-1].t==='rparen')) out.push({t:'op',v:'*'}); // 2(3) → 2*(3)
      out.push({t:'lparen'}); i++; continue;
    }
    if(c===')'){ out.push({t:'rparen'}); i++; continue; }
    if(c==='%'){ out.push({t:'pct'}); i++; continue; }
    i++;
  }
  return out;
}

// Recursive-descent parser producing an AST, then evaluated recursively.
// Gives correct precedence, right-associative ^, unary +/−, parentheses,
// implicit multiplication and classic percentage behaviour ("200 − 10%"
// means "200 − (10% of 200)" = 180).
function calcParse(expr){
  const toks=calcTokenize(String(expr||'').trim());
  if(!toks.length) return null;
  let pos=0;
  const peek=()=>toks[pos];
  const next=()=>toks[pos++];
  function parseExpr(minPrec){
    let left=parseUnary();
    for(;;){
      const tk=peek();
      if(!tk || tk.t!=='op') break;
      const prec=CALC_PREC[tk.v]||0;
      if(prec < minPrec) break;
      const fn=next().v;
      left={op:'bin',fn,a:left,b:parseExpr(CALC_RIGHT.includes(fn)?prec:prec+1)};
    }
    return left;
  }
  function parseUnary(){
    const tk=peek();
    if(tk && tk.t==='op' && (tk.v==='-'||tk.v==='+')){
      next();
      const arg=parseUnary();
      return tk.v==='-' ? {op:'neg',v:arg} : arg;
    }
    return parsePrimary();
  }
  function parsePrimary(){
    const tk=next();
    if(!tk) throw new Error('That expression is not complete.');
    if(tk.t==='num'){
      let n={v:tk.v};
      if(peek() && peek().t==='pct'){ next(); n={op:'pct',v:n}; }
      return n;
    }
    if(tk.t==='lparen'){
      const e=parseExpr(0);
      const close=next();
      if(!close || close.t!=='rparen') throw new Error('Missing closing parenthesis.');
      return (peek() && peek().t==='pct') ? (next(), {op:'pct',v:e}) : e;
    }
    if(tk.t==='rparen') throw new Error('Unbalanced parentheses.');
    throw new Error('That expression is not valid.');
  }
  const node=parseExpr(0);
  if(pos<toks.length) throw new Error('That expression is not valid.');
  return node;
}

function calcEvalNode(node){
  if(node.op){
    switch(node.op){
      case 'neg': return -calcEvalNode(node.v);
      case 'pct': return calcEvalNode(node.v)/100;
      case 'bin': {
        const a=calcEvalNode(node.a);
        let b=calcEvalNode(node.b);
        if(node.b && node.b.op==='pct' && (node.fn==='+'||node.fn==='-')) b=a*b;
        switch(node.fn){
          case '+': return a+b;
          case '-': return a-b;
          case '*': return a*b;
          case '/': if(b===0) throw new Error('Cannot divide by zero.'); return a/b;
          case '^': { const r=Math.pow(a,b); if(!Number.isFinite(r)) throw new Error('That power is not real.'); return r; }
        }
      }
    }
  }
  return node.v;
}

function calcEvaluate(expr){
  const node=calcParse(expr);
  if(node===null) return null;
  const v=calcEvalNode(node);
  if(!Number.isFinite(v)) throw new Error('That calculation is not valid.');
  return parseFloat(v.toPrecision(12));   // kill 0.1+0.2 style float dust
}

const calcFmt = n => (n===null||n===undefined||n==='') ? '' :
  Number(n).toLocaleString('en-IN',{maximumFractionDigits:10});
const calcPretty = s => String(s).replace(/\*/g,'×').replace(/\//g,'÷').replace(/\^/g,'^');
const calcPlain = v => {
  const p=Number(v.toPrecision(12));
  return (Math.abs(p)>=1e15 || (p!==0 && Math.abs(p)<1e-6)) ? p.toExponential(10) : String(p);
};

// Isolate the last number typed so the +/− (sign toggle) can act on it. A
// leading minus is kept with the number only when it is unary (preceded by an
// operator or the start of the entry).
function splitTrailingNumber(expr){
  const s=String(expr||'');
  const m=/(\d+(?:\.\d*)?|\.\d+)$/.exec(s);
  if(!m) return null;
  const num=m[0];
  let head=s.slice(0,m.index);
  let neg=false;
  const sign=head.slice(-1);
  const prev=head.slice(0,-1);
  if((sign==='-'||sign==='+') && (prev==='' || /[+\-*/^(]/.test(prev.slice(-1)))){
    neg=true; head=prev;
  }
  return { head, neg, num };
}

/* ── Pages ──────────────────────────────────────────────────────────────── */

/* ── Calculator page ────────────────────────────────────────────────────── */

function buildCalcModule(){
  const pg=h('div',{class:'page calc-page fade'});
  pg.appendChild(h('div',{class:'page-hdr'},h('h2',{},'🧮 Calculator')));
  pg.appendChild(buildCalculator());
  return pg;
}

/* ── Simple calculator ──────────────────────────────────────────────────── */

function buildCalculator(){
  const wrap=h('div',{class:'card calc-card'});

  const disp=h('div',{class:'calc-disp'});
  const exprLine=h('div',{class:'calc-expr'});
  const resLine=h('div',{class:'calc-res'});
  disp.appendChild(exprLine);
  disp.appendChild(resLine);
  wrap.appendChild(disp);

  let pad=null;

  // Auto-scale the result font so long numbers stay inside the display.
  const scaleRes=()=>{
    const len=(calcError||calcResult||'0').replace(/[,\s]/g,'').length;
    resLine.style.fontSize = len>20 ? '26px' : (len>14 ? '34px' : (len>10 ? '44px' : ''));
  };

  const preview=()=>{
    if(!calcExpr){ calcResult=''; scaleRes(); return; }
    try{ const v=calcEvaluate(calcExpr); calcResult=(v===null||v===undefined)?'':calcFmt(v); calcError=''; }
    catch(e){ calcResult=''; }
    scaleRes();
  };
  const repaint=()=>{
    if(!pad) return;
    exprLine.textContent=calcExpr?calcPretty(calcExpr):'';
    resLine.textContent=calcError||calcResult||'0';
    resLine.classList.toggle('err', !!calcError);
    pad.querySelectorAll('.calc-key[data-op]').forEach(k=>k.classList.toggle('act', !!calcOp && k.dataset.op===calcOp));
    const acBtn=pad.querySelector('.calc-key[data-clear]');
    if(acBtn) acBtn.textContent=(calcError || /[\d.]$/.test(calcExpr)) ? 'C' : 'AC';
    scaleRes();
  };

  const toggleSign=()=>{
    const t=splitTrailingNumber(calcExpr);
    if(!t){
      if(!calcExpr){
        if(calcResult && calcResult!=='0'){ calcExpr=calcPlain(-parseFloat(String(calcResult).replace(/,/g,''))); calcResult=''; calcError=''; }
        else { calcExpr='-'; }
      } else if(calcResult!==''){ calcExpr=calcPlain(-parseFloat(String(calcResult).replace(/,/g,''))); calcResult=''; calcError=''; }
      preview(); repaint(); return;
    }
    calcExpr=t.head+(t.neg?'':'-')+t.num;
    preview(); repaint();
  };

  const typeDigit=d=>{
    if(calcStartNew || calcError){ calcExpr=''; calcResult=''; calcError=''; calcStartNew=false; }
    calcExpr+=d; repaint();
  };

  const typeDecimal=()=>{
    if(calcStartNew || calcError){ calcExpr=''; calcResult=''; calcError=''; calcStartNew=false; }
    const tail=calcExpr.split(/[+\-*/%]/).pop();
    if(/\./.test(tail)) return;
    if(!tail) calcExpr+='0';
    calcExpr+='.'; calcError=''; repaint();
  };

  const evaluate=()=>{
    try{
      const v=calcEvaluate(calcExpr);
      if(v===null||v===undefined||!calcExpr){ if(calcResult==='' && calcExpr!=='') calcResult='0'; return; }
      calcResult=calcFmt2(v); calcOp=''; scaleRes();
    }catch(e){ calcError=e.message; calcResult=''; }
    calcStartNew=true; repaint();
  };

  const press=key=>{
    if(key==='AC'||key==='C'){
      if(key==='C'){ calcExpr=''; calcResult=''; calcError=''; calcStartNew=false; }
      else { calcExpr=''; calcResult=''; calcError=''; calcOp=''; calcStartNew=false; }
      repaint(); return;
    }
    if(key==='back'){
      if(calcError){ calcError=''; calcResult=''; }
      else if(!calcStartNew){ calcExpr=calcExpr.slice(0,-1); }
      preview(); repaint(); return;
    }
    if(key==='+/-'){ toggleSign(); return; }
    if(key==='='){ evaluate(); return; }
    if('+-*/'.includes(key)){
      if(calcStartNew && calcResult!==''){ calcExpr=calcPlain(parseNum(calcResult)); calcStartNew=false; calcResult=''; }
      if(calcError){ calcExpr=''; calcResult=''; calcError=''; calcStartNew=false; }
      if(!calcExpr){ calcExpr= key==='-' ? '-' : '0'; if(key!=='-'){ calcExpr+=key; } }
      else if(/[+\-*/%]$/.test(calcExpr)){ calcExpr=calcExpr.slice(0,-1); calcExpr+=key; }
      else { calcExpr+=key; }
      calcOp=key; calcError=''; calcStartNew=false; preview(); repaint(); return;
    }
    if(key==='%'){
      if(calcStartNew && calcResult!==''){ calcExpr=calcPlain(parseNum(calcResult)); calcStartNew=false; calcResult=''; }
      if(calcError){ calcExpr=''; calcResult=''; calcError=''; calcStartNew=false; }
      if(!/[\d.]$/.test(calcExpr)) return;
      calcExpr+='%'; calcOp=''; calcError=''; preview(); repaint(); return;
    }
    if(key==='.'){ typeDecimal(); return; }
    typeDigit(key);
  };

  pad=h('div',{class:'calc-pad'});
  const K=(l,cls,k,op)=>{
    const b=h('button',{type:'button',class:'calc-key '+(cls||'').trim(),'aria-label':k==='AC'?'All clear':k});
    b.textContent=l;
    if(op) b.dataset.op=op;
    if(k==='AC') b.dataset.clear='1';
    b.addEventListener('click',()=>press(k));
    pad.appendChild(b);
    return b;
  };
  // Row 1: AC, +/-, %, ÷
  K('AC','k-fn','AC'); K('+/−','k-fn','+/-'); K('%','k-fn','%'); K('÷','k-op','/','/');
  // Row 2: 7 8 9 ×
  K('7','','7'); K('8','','8'); K('9','','9'); K('×','k-op','*','*');
  // Row 3: 4 5 6 −
  K('4','','4'); K('5','','5'); K('6','','6'); K('−','k-op','-','-');
  // Row 4: 1 2 3 +
  K('1','','1'); K('2','','2'); K('3','','3'); K('+','k-op','+','+');
  // Row 5: 0 (wide) . =
  const z=K('0','','0'); z.classList.add('calc-0');
  K('.','','.'); K('=','k-eq','=');
  wrap.appendChild(pad);

  // Physical keyboard: digits, operators, %, decimal, Enter (=), Backspace and
  // Escape (AC). Re-attached on every render so it never stacks when the
  // Calculator is reopened. Only handled while the Calculator page is active
  // AND the focus is not inside a text field — otherwise keys like Backspace,
  // Enter, digits or '=' would be stolen from the AI chat, expense forms, etc.
  if(window.__calcKeyH) document.removeEventListener('keydown',window.__calcKeyH);
  const onKey=e=>{
    if(page!=='calc') return;
    const t=e.target;
    if(t && (t.tagName==='INPUT'||t.tagName==='TEXTAREA'||t.tagName==='SELECT'||t.isContentEditable)) return;
    if(e.ctrlKey||e.metaKey||e.altKey) return;
    const k=e.key;
    if(k==='Escape'){ press('AC'); e.preventDefault(); return; }
    if(k==='Enter'||k==='='){ press('='); e.preventDefault(); return; }
    if(k==='Backspace'){ press('back'); e.preventDefault(); return; }
    if(/^[0-9]$/.test(k)){ press(k); e.preventDefault(); return; }
    if(k==='.'){ press('.'); e.preventDefault(); return; }
    if(k==='%'){ press('%'); e.preventDefault(); return; }
    if(k==='+'||k==='*'||k==='/'||k==='-'){ press(k==='+'?'+':(k==='*'?'*':(k==='/'?'/':'-'))); e.preventDefault(); }
  };
  window.__calcKeyH=onKey;
  document.addEventListener('keydown',onKey);

  repaint();
  return wrap;
}

function parseNum(s){
  const n=parseFloat(String(s||'0').replace(/,/g,''));
  return Number.isFinite(n)?n:0;
}
const calcFmt2 = n => {
  if(n===null||n===undefined||n==='') return '';
  if(!Number.isFinite(n)) return 'Error';
  return Number(n).toLocaleString('en-IN',{maximumFractionDigits:10,useGrouping:true});
};

// ─── NAV ──────────────────────────────────────────────────────────────────────
function buildNav(){
  const nav=h('nav',{class:'bnav'});
  const items=[
    {icon:'fa-home',label:'Home',p:'dashboard'},
    {icon:'fa-receipt',label:'Expense',p:'expense'},
    {icon:'fa-chart-pie',label:'Stats',p:'stats'},
    {icon:'fa-arrow-trend-up',label:'Income',p:'income',center:true},
    {icon:'fa-champagne-glasses',label:'Events',p:'events'},
    {icon:'fa-calculator',label:'Calculator',p:'calc'},
    {icon:'fa-gear',label:'Settings',p:'settings'},
  ];
  const activeFor = page==='add-expense'?'expense':(page==='add-income'?'income':page);
  items.forEach(item=>{
    const isActive=activeFor===item.p;
    const btn=h('button',{type:'button',class:`ni${item.center?' nc':''}${isActive?' act':''}`});
    if(item.center){
      btn.appendChild(h('div',{class:'ncb'},h('i',{class:`fas ${item.icon}`})));
    } else {
      btn.appendChild(h('i',{class:`fas ${item.icon}`}));
    }
    btn.appendChild(h('span',{},item.label));
    btn.addEventListener('click',async ()=>{
      setPage(item.p);
      if(item.p!=='settings') settingsPage=null;
      render();
      if(item.p==='events'){ eventSub='list'; refreshEventsList(); }
    });
    nav.appendChild(btn);
  });
  return nav;
}

// ─── BOOT ─────────────────────────────────────────────────────────────────────
async function boot(){
  applyTheme();
  let lastDay=toDay();
  setInterval(()=>{ const now=toDay(); if(now!==lastDay){ lastDay=now; if(state.user) render(); } }, 30000);
  try {
    const d = await api('api/auth.php');
    if (d.user) {
      state.user = d.user;
      const hashPage = getPageFromHash();
      page = hashPage || 'dashboard';
      render();
      try { await refreshAll(); } catch(e) { console.error('refreshAll failed:', e); }
      render();
      return;
    }
  } catch (e) {
    state.user = null;
  }
  window.location.href = 'signin.php';
}

window.addEventListener('hashchange', ()=>{
  if(state.user){
    const hashPage = getPageFromHash();
    if(hashPage && hashPage !== page){
      page = hashPage;
      render();
    }
  }
});

applyTheme();
boot();

</script>
</body>
</html>
