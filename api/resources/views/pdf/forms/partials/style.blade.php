{{--
    Shared rules for the printed paper forms (pdf/forms/*).

    The paper is black ink on white, ruled in boxes, so these are too: no
    brand colour, no rounded corners. A filled answer prints in a darker,
    slightly larger face than the form's own printed labels so the two read
    apart the way handwriting reads apart from print on the paper — the
    `.v` class. Checkboxes are the DejaVu glyphs U+2610 / U+2612, which dompdf
    embeds, rather than drawn boxes that drift off the baseline.
--}}
<style>
    @page { margin: 20px 28px 22px 28px; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #000; font-size: 8.6px; line-height: 1.25; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; padding: 2px 4px; }
    .b { border: 1px solid #000; }
    .bt { border-top: 1px solid #000; }
    .bb { border-bottom: 1px solid #000; }
    .bl { border-left: 1px solid #000; }
    .br { border-right: 1px solid #000; }
    .box { border: 1.2px solid #000; }
    .grid td, .grid th { border: 1px solid #000; }
    .grid table.plain td { border: 0; }
    .grid table.plain td.bl { border-left: 1px solid #000; }
    .bold { font-weight: bold; }
    .it { font-style: italic; }
    .c { text-align: center; }
    .r { text-align: right; }
    .up { text-transform: uppercase; }
    .small { font-size: 7.4px; }
    .tiny { font-size: 6.6px; }
    .big { font-size: 10.5px; }
    .v { font-family: DejaVu Sans Mono, monospace; font-weight: bold; font-size: 9px; color: #0b1f6b; }
    .line { border-bottom: 1px solid #000; display: inline-block; min-width: 60px; padding: 0 3px; }
    .fill { border-bottom: 1px solid #000; }
    .ck { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; line-height: 1; }
    .sec { background: #000; color: #fff; font-weight: bold; padding: 2px 5px; }
    .secl { font-weight: bold; padding: 3px 4px; border-top: 1px solid #000; border-bottom: 1px solid #000; }
    .sig { border-top: 1px solid #000; text-align: center; padding-top: 1px; font-size: 7.4px; font-weight: bold; }
    .page-break { page-break-after: always; }
    .muted { color: #444; }
    .cut { border-top: 1px dashed #000; margin: 8px 0; }
    .gen { position: fixed; bottom: -14px; left: 0; right: 0; font-size: 6.4px; color: #555; }
</style>
