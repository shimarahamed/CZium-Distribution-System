<?php
// ═══════════════════════════════════════════════════════════
// Pdf — minimal, self-contained PDF writer.
//
// WHY THIS EXISTS: the target host has no Composer and no PDF
// extension. This class emits a valid PDF 1.4 document using only
// core PHP string functions, so it works on any shared host.
//
// Supports: A4 portrait, Helvetica family, text, lines, filled
// rectangles, right/centre alignment, word-wrap, and multi-page
// flow with automatic page breaks.
//
// Coordinates are top-left origin, in points (72pt = 1 inch).
// A4 = 595.28 x 841.89 pt.
// ═══════════════════════════════════════════════════════════

class Pdf {
    private const W = 595.28;   // A4 width  (pt)
    private const H = 841.89;   // A4 height (pt)

    private array  $pages   = [];
    private string $buf     = '';
    private float  $y       = 0;
    private array  $objects = [];

    public float $marginL = 40;
    public float $marginR = 40;
    public float $marginT = 40;
    public float $marginB = 50;

    /** Fonts actually referenced, mapped to PDF resource names. */
    private array $fontsUsed = ['F1' => 'Helvetica'];

    public function __construct() {
        $this->newPage();
    }

    // ─── Page handling ────────────────────────────────────
    public function newPage(): void {
        if ($this->buf !== '') $this->pages[] = $this->buf;
        $this->buf = '';
        $this->y   = $this->marginT;
    }

    public function pageWidth(): float  { return self::W; }
    public function contentWidth(): float { return self::W - $this->marginL - $this->marginR; }
    public function getY(): float { return $this->y; }
    public function setY(float $y): void { $this->y = $y; }
    public function moveY(float $d): void { $this->y += $d; }

    /** Break to a new page if less than $need points remain. */
    public function ensure(float $need): bool {
        if ($this->y + $need > self::H - $this->marginB) {
            $this->newPage();
            return true;
        }
        return false;
    }

    // ─── Escaping ─────────────────────────────────────────
    /**
     * WinAnsiEncoding positions for common Unicode punctuation that would
     * otherwise be lost. Without this, em-dashes and curly quotes pasted from
     * Word render as "?" in the invoice.
     */
    private const WINANSI = [
        0x2018 => 0x91, 0x2019 => 0x92,   // ‘ ’
        0x201C => 0x93, 0x201D => 0x94,   // “ ”
        0x2022 => 0x95,                   // •
        0x2013 => 0x96, 0x2014 => 0x97,   // – —
        0x2026 => 0x85,                   // …
        0x2039 => 0x8B, 0x203A => 0x9B,   // ‹ ›
        0x20AC => 0x80,                   // €
        0x2122 => 0x99,                   // ™
    ];

    /** Characters with no WinAnsi glyph, replaced with a readable ASCII form. */
    private const FALLBACK = [
        0x20B9 => 'Rs.',  // ₹
        0x20A8 => 'Rs.',  // ₨
        0x00A0 => ' ',    // nbsp
    ];

    private function esc(string $s): string {
        $s = str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s);
        $out = '';
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($s, $i, 1, 'UTF-8');
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp === false)                      { $out .= '?'; }
            elseif ($cp < 128)                      { $out .= $ch; }
            elseif (isset(self::FALLBACK[$cp]))     { $out .= self::FALLBACK[$cp]; }
            elseif (isset(self::WINANSI[$cp]))      { $out .= chr(self::WINANSI[$cp]); }
            elseif ($cp < 256)                      { $out .= chr($cp); }
            else                                    { $out .= '?'; }  // emoji / Tamil — no Helvetica glyph
        }
        return $out;
    }

    private function fontRef(string $style): string {
        $map = [
            ''   => ['F1', 'Helvetica'],
            'B'  => ['F2', 'Helvetica-Bold'],
            'I'  => ['F3', 'Helvetica-Oblique'],
            'BI' => ['F4', 'Helvetica-BoldOblique'],
        ];
        [$ref, $name] = $map[$style] ?? $map[''];
        $this->fontsUsed[$ref] = $name;
        return $ref;
    }

    /**
     * Real Helvetica advance widths (units per 1000 em) for ASCII 32–126.
     * An averaged approximation is not good enough here: right-aligned
     * headings and currency columns drift and overflow their cell.
     */
    private const W_REG = [
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
        556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,
    ];
    private const W_BOLD = [
        278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
        975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,
        333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,
        611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,
    ];

    /** Text width in points, using real Helvetica metrics. */
    public function textWidth(string $s, float $size, string $style = ''): float {
        $tbl   = str_contains($style, 'B') ? self::W_BOLD : self::W_REG;
        $total = 0;
        $len   = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $cp = mb_ord(mb_substr($s, $i, 1, 'UTF-8'), 'UTF-8');
            if ($cp === false)              { $total += 556; }
            elseif ($cp >= 32 && $cp <= 126){ $total += $tbl[$cp - 32]; }
            elseif ($cp === 0x2014 || $cp === 0x2013) { $total += 556; }  // em/en dash
            elseif ($cp === 0x2022)         { $total += 350; }            // bullet
            else                            { $total += 556; }
        }
        return $total * $size / 1000;
    }

    // ─── Drawing primitives ───────────────────────────────
    public function text(string $s, float $x, ?float $y = null, float $size = 10,
                         string $style = '', array $rgb = [0,0,0]): void {
        $y = $y ?? $this->y;
        $f = $this->fontRef($style);
        $py = self::H - $y - $size;   // convert to PDF bottom-left origin
        $this->buf .= sprintf(
            "BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $f, $size, $rgb[0]/255, $rgb[1]/255, $rgb[2]/255, $x, $py, $this->esc($s)
        );
    }

    public function textRight(string $s, float $xRight, ?float $y = null, float $size = 10,
                              string $style = '', array $rgb = [0,0,0]): void {
        $this->text($s, $xRight - $this->textWidth($s, $size, $style), $y, $size, $style, $rgb);
    }

    public function textCenter(string $s, float $xCenter, ?float $y = null, float $size = 10,
                               string $style = '', array $rgb = [0,0,0]): void {
        $this->text($s, $xCenter - $this->textWidth($s, $size, $style)/2, $y, $size, $style, $rgb);
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb = [240,240,240]): void {
        $this->buf .= sprintf(
            "%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
            $rgb[0]/255, $rgb[1]/255, $rgb[2]/255, $x, self::H - $y - $h, $w, $h
        );
    }

    public function line(float $x1, float $y1, float $x2, float $y2,
                         float $width = 0.5, array $rgb = [200,200,200]): void {
        $this->buf .= sprintf(
            "%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $rgb[0]/255, $rgb[1]/255, $rgb[2]/255, $width,
            $x1, self::H - $y1, $x2, self::H - $y2
        );
    }

    /** Horizontal rule across the content area at the current Y. */
    public function hr(?float $y = null, array $rgb = [220,220,220], float $w = 0.5): void {
        $y = $y ?? $this->y;
        $this->line($this->marginL, $y, self::W - $this->marginR, $y, $w, $rgb);
    }

    /** Word-wrapped paragraph. Returns the height consumed. */
    public function paragraph(string $s, float $x, float $w, float $size = 9,
                              string $style = '', array $rgb = [0,0,0], float $lead = 1.35): float {
        $words = preg_split('/\s+/', trim($s)) ?: [];
        $line = '';
        $startY = $this->y;
        foreach ($words as $word) {
            $try = $line === '' ? $word : "$line $word";
            if ($this->textWidth($try, $size, $style) > $w && $line !== '') {
                $this->text($line, $x, $this->y, $size, $style, $rgb);
                $this->y += $size * $lead;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $this->text($line, $x, $this->y, $size, $style, $rgb);
            $this->y += $size * $lead;
        }
        return $this->y - $startY;
    }

    // ─── Output ───────────────────────────────────────────
    private function addObj(string $content): int {
        $this->objects[] = $content;
        return count($this->objects);
    }

    public function output(): string {
        if ($this->buf !== '') { $this->pages[] = $this->buf; $this->buf = ''; }
        if (!$this->pages) $this->pages[] = '';

        $this->objects = [];
        $nPages   = count($this->pages);
        $catalogN = 1;
        $pagesN   = 2;

        // Reserve 1 (catalog) and 2 (pages tree)
        $this->addObj('');
        $this->addObj('');

        // Font objects
        $fontRefs = [];
        foreach ($this->fontsUsed as $ref => $base) {
            $n = $this->addObj("<< /Type /Font /Subtype /Type1 /BaseFont /$base /Encoding /WinAnsiEncoding >>");
            $fontRefs[$ref] = $n;
        }
        $fontDict = '';
        foreach ($fontRefs as $ref => $n) $fontDict .= "/$ref $n 0 R ";

        // Page + content objects
        $kids = [];
        foreach ($this->pages as $content) {
            $streamN = $this->addObj("<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream");
            $pageN   = $this->addObj(
                "<< /Type /Page /Parent $pagesN 0 R " .
                sprintf("/MediaBox [0 0 %.2F %.2F] ", self::W, self::H) .
                "/Resources << /Font << $fontDict>> >> /Contents $streamN 0 R >>"
            );
            $kids[] = "$pageN 0 R";
        }

        $this->objects[$catalogN - 1] = "<< /Type /Catalog /Pages $pagesN 0 R >>";
        $this->objects[$pagesN - 1]   = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $nPages >>";

        // Assemble with xref
        $out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($this->objects as $i => $obj) {
            $offsets[$i + 1] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n$obj\nendobj\n";
        }
        $xrefPos = strlen($out);
        $count   = count($this->objects) + 1;
        $out    .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size $count /Root $catalogN 0 R >>\nstartxref\n$xrefPos\n%%EOF";
        return $out;
    }

    /** Stream the PDF to the browser and exit. */
    public function send(string $filename, bool $inline = true): never {
        $pdf = $this->output();
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Length: ' . strlen($pdf));
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') .
                   '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }
        echo $pdf;
        exit;
    }
}
