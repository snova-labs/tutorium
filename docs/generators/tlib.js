// tlib.js — shared docx helpers for the snova-labs platform document set
const {
  Document, Packer, Paragraph, TextRun, HeadingLevel, AlignmentType,
  Table, TableRow, TableCell, WidthType, BorderStyle, ShadingType,
  LevelFormat, TableOfContents, PageNumber, Footer, PageBreak,
} = require('docx');
const fs = require('fs');

const PRODUCT = 'Tutorium';           // provisional product name — single source of truth
const ORG = 'snova-labs';
const AUTHOR = 'snova';
const DOCDATE = '16 August 2026';

const C = {
  primary: '1F4E6B', dark: '13323F', text: '1A2332', muted: '5A6B7E',
  border: 'DCE3EA', accent: 'C2761E', codebg: 'F5F7F9', zebra: 'F7F9FB',
};

const numbering = {
  config: [
    { reference: 'bullets', levels: [
      { level: 0, format: LevelFormat.BULLET, text: '\u2022', alignment: AlignmentType.LEFT,
        style: { paragraph: { indent: { left: 460, hanging: 240 } } } },
      { level: 1, format: LevelFormat.BULLET, text: '\u25E6', alignment: AlignmentType.LEFT,
        style: { paragraph: { indent: { left: 880, hanging: 240 } } } },
    ] },
    { reference: 'nums', levels: [
      { level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.LEFT,
        style: { paragraph: { indent: { left: 460, hanging: 300 } } } },
    ] },
  ],
};

function H1(t) { return new Paragraph({ heading: HeadingLevel.HEADING_1, spacing: { before: 320, after: 150 }, children: [new TextRun({ text: t, bold: true, color: C.dark, size: 30 })] }); }
function H2(t) { return new Paragraph({ heading: HeadingLevel.HEADING_2, spacing: { before: 250, after: 110 }, children: [new TextRun({ text: t, bold: true, color: C.primary, size: 25 })] }); }
function H3(t) { return new Paragraph({ heading: HeadingLevel.HEADING_3, spacing: { before: 190, after: 90 }, children: [new TextRun({ text: t, bold: true, color: C.text, size: 22 })] }); }

function runs(text, base = {}) {
  const out = [];
  for (const p of String(text).split(/(\*\*[^*]+\*\*|`[^`]+`)/g).filter(Boolean)) {
    if (p.startsWith('**') && p.endsWith('**')) out.push(new TextRun({ text: p.slice(2, -2), bold: true, ...base }));
    else if (p.startsWith('`') && p.endsWith('`')) out.push(new TextRun({ text: p.slice(1, -1), font: 'Consolas', size: 19, color: C.dark, ...base }));
    else out.push(new TextRun({ text: p, ...base }));
  }
  return out;
}

function P(t, opts = {}) { return new Paragraph({ spacing: { after: 120, line: 276 }, alignment: AlignmentType.JUSTIFIED, ...opts.par, children: runs(t, opts.run) }); }
function B(t, level = 0) { return new Paragraph({ numbering: { reference: 'bullets', level }, spacing: { after: 60, line: 264 }, children: runs(t) }); }
function N(t) { return new Paragraph({ numbering: { reference: 'nums', level: 0 }, spacing: { after: 60, line: 264 }, children: runs(t) }); }
function NOTE(t) {
  return new Paragraph({
    spacing: { before: 100, after: 140 }, indent: { left: 220 },
    border: { left: { style: BorderStyle.SINGLE, size: 18, color: C.accent } },
    shading: { type: ShadingType.CLEAR, fill: 'FBF4EA' },
    children: runs('  ' + t, { size: 20 }),
  });
}
function CODE(lines) {
  return lines.map((l, i) => new Paragraph({
    spacing: { after: i === lines.length - 1 ? 140 : 0 },
    shading: { type: ShadingType.CLEAR, fill: C.codebg },
    indent: { left: 220 },
    children: [new TextRun({ text: l === '' ? ' ' : l, font: 'Consolas', size: 18, color: '15314F' })],
  }));
}

const PAGE_W = 11906, MARGIN = 1080;
const CONTENT_W = PAGE_W - MARGIN * 2;

function TBL(headers, rows, widths) {
  const w = widths || headers.map(() => Math.floor(CONTENT_W / headers.length));
  const mk = (cells, hdr, i) => new TableRow({
    tableHeader: hdr,
    children: cells.map((c, j) => new TableCell({
      width: { size: w[j], type: WidthType.DXA },
      shading: hdr ? { type: ShadingType.CLEAR, fill: C.primary } : (i % 2 === 1 ? { type: ShadingType.CLEAR, fill: C.zebra } : undefined),
      margins: { top: 60, bottom: 60, left: 100, right: 100 },
      borders: {
        top: { style: BorderStyle.SINGLE, size: 4, color: C.border },
        bottom: { style: BorderStyle.SINGLE, size: 4, color: C.border },
        left: { style: BorderStyle.SINGLE, size: 4, color: C.border },
        right: { style: BorderStyle.SINGLE, size: 4, color: C.border },
      },
      children: [new Paragraph({ spacing: { after: 0, line: 240 }, children: runs(c, hdr ? { bold: true, color: 'FFFFFF', size: 19 } : { size: 19 }) })],
    })),
  });
  return new Table({ width: { size: CONTENT_W, type: WidthType.DXA }, columnWidths: w, rows: [mk(headers, true, 0), ...rows.map((r, i) => mk(r, false, i + 1))] });
}

function BREAK() { return new Paragraph({ children: [new PageBreak()] }); }

function cover(title, subtitle, docId, extra = []) {
  return [
    new Paragraph({ spacing: { before: 2300, after: 160 }, alignment: AlignmentType.CENTER,
      children: [new TextRun({ text: PRODUCT.toUpperCase(), bold: true, color: C.primary, size: 40, characterSpacing: 70 })] }),
    new Paragraph({ spacing: { after: 380 }, alignment: AlignmentType.CENTER,
      children: [new TextRun({ text: 'Cohort management platform for tutoring centres, language schools and training institutes', color: C.muted, size: 21 })] }),
    new Paragraph({ spacing: { after: 180 }, alignment: AlignmentType.CENTER,
      border: { top: { style: BorderStyle.SINGLE, size: 8, color: C.border }, bottom: { style: BorderStyle.SINGLE, size: 8, color: C.border } },
      children: [new TextRun({ text: title, bold: true, size: 42, color: C.dark })] }),
    new Paragraph({ spacing: { after: 1100 }, alignment: AlignmentType.CENTER,
      children: [new TextRun({ text: subtitle, size: 23, color: C.muted, italics: true })] }),
    TBL(['Field', 'Value'], [
      ['Document ID', docId],
      ['Version', '0.1 — draft for review'],
      ['Date', DOCDATE],
      ['Owner', `${AUTHOR} · github.com/${ORG}`],
      ['Product name', `${PRODUCT} — **provisional**; trademark and domain clearance pending. The codebase uses no product name (see SL-ARC-002 §1), so a rename is a configuration change.`],
      ['Status', 'Draft — open for edits and additions'],
      ...extra,
    ], [2500, 7246]),
    BREAK(),
  ];
}

function buildDoc(children, footerText) {
  return new Document({
    numbering,
    styles: { default: { document: { run: { font: 'Calibri', size: 21, color: C.text } } } },
    features: { updateFields: true },
    sections: [{
      properties: { page: { margin: { top: MARGIN, bottom: MARGIN, left: MARGIN, right: MARGIN } } },
      footers: { default: new Footer({ children: [new Paragraph({
        alignment: AlignmentType.CENTER,
        border: { top: { style: BorderStyle.SINGLE, size: 4, color: C.border } },
        children: [
          new TextRun({ text: footerText + '   |   Page ', size: 17, color: C.muted }),
          new TextRun({ children: [PageNumber.CURRENT], size: 17, color: C.muted }),
          new TextRun({ text: ' of ', size: 17, color: C.muted }),
          new TextRun({ children: [PageNumber.TOTAL_PAGES], size: 17, color: C.muted }),
        ],
      })] }) },
      children,
    }],
  });
}

async function save(doc, path) {
  const buf = await Packer.toBuffer(doc);
  fs.writeFileSync(path, buf);
  console.log('wrote', path, (buf.length / 1024).toFixed(0) + ' KB');
}

module.exports = { PRODUCT, ORG, AUTHOR, H1, H2, H3, P, B, N, NOTE, CODE, TBL, BREAK, cover, buildDoc, save, TableOfContents, Paragraph, TextRun };
