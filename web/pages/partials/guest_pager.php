<?php
// pages/partials/guest_pager.php
// แถบแบ่งหน้าของ guest_list — พอร์ต "รูปแบบ" จาก wp-bhikkhu-scholar (เหมือนที่ทำใน Sec/member_pager.php)
//   · หน้าต่างเลขหน้า: หน้าแรก + หน้าสุดท้าย + รอบหน้าปัจจุบัน (±around) คั่นด้วย '…'
//   · ช่องกรอก "ไปหน้า [ ] [ไป]"
// Reg นำทางด้วย query-string (index.php?...&p=N) → ใช้ .pagination ของ Bootstrap ให้เข้าธีม
// ต่างจาก Sec: Reg ไม่มีลำดับที่จัดเอง (เรียงตาม last_stay_at) → ลิงก์ไม่มี keep=1

if (!function_exists('guestPageWindow')) {
    /**
     * เลขหน้าใกล้เคียง: 1, หน้าสุดท้าย, และ ±$around รอบหน้าปัจจุบัน — คั่นช่องว่างด้วย '…'
     * (ยกอัลกอริทึมจาก wp-bhikkhu-scholar class-shortcode.php::page_window)
     * @return array<int|string>
     */
    function guestPageWindow(int $cur, int $pages, int $around = 2): array
    {
        $out  = [];
        $prev = 0;
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === 1 || $i === $pages || abs($i - $cur) <= $around) {
                if ($prev && $i - $prev > 1) {
                    $out[] = '…';
                }
                $out[] = $i;
                $prev  = $i;
            }
        }
        return $out;
    }
}

if (!function_exists('guestPagerHtml')) {
    /**
     * เรนเดอร์ "แถบเลขหน้า" อย่างเดียว (ไม่รวมดร็อบดาวจำนวนต่อหน้า/บล็อกสรุปผล — ผู้เรียกจัดวางเอง)
     * คืน '' เมื่อไม่มีอะไรต้องแสดง (หน้าเดียว)
     *
     * @param array $get     ตัวกรองปัจจุบัน (ปกติคือ $_GET) — คงพารามิเตอร์เดิมไว้ในลิงก์
     * @param int   $cur     หน้าปัจจุบัน
     * @param int   $pages   จำนวนหน้าทั้งหมด (โหมดนับ exact)
     * @param bool  $exact   true = นับ exact (มี window + ช่องไปหน้า) · false = ค้นแบบกันค้าง (ก่อนหน้า/ถัดไป)
     * @param bool  $hasMore โหมดไม่นับ exact: มีหน้าถัดไปหรือไม่
     */
    function guestPagerHtml(array $get, int $cur, int $pages, bool $exact, bool $hasMore = false): string
    {
        $href = function (int $p) use ($get) {
            $qs = array_merge($get, ['p' => $p]);
            return htmlspecialchars('index.php?' . http_build_query($qs), ENT_QUOTES, 'UTF-8');
        };

        // ---- โหมดค้นแบบกันค้าง: ไม่นับ exact → ก่อนหน้า / เลขหน้า / ถัดไป ----
        if (!$exact) {
            if ($cur <= 1 && !$hasMore) {
                return '';
            }
            $h  = '<nav class="gl-pager" aria-label="pagination">';
            $h .= '<ul class="pagination pagination-sm mb-0">';
            $h .= '<li class="page-item' . ($cur <= 1 ? ' disabled' : '') . '">'
                . '<a class="page-link" href="' . $href($cur - 1) . '" aria-label="' . e('list.prev_page') . '"><i class="bi bi-chevron-left"></i></a></li>';
            $h .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $cur . '</span></li>';
            $h .= '<li class="page-item' . (!$hasMore ? ' disabled' : '') . '">'
                . '<a class="page-link" href="' . $href($cur + 1) . '" aria-label="' . e('list.next_page') . '"><i class="bi bi-chevron-right"></i></a></li>';
            $h .= '</ul></nav>';
            return $h;
        }

        // ---- โหมดนับ exact ----
        if ($pages <= 1) {
            return '';
        }
        $h  = '<nav class="gl-pager" aria-label="pagination">';
        $h .= '<ul class="pagination pagination-sm mb-0 flex-wrap">';

        // ก่อนหน้า
        $h .= '<li class="page-item' . ($cur <= 1 ? ' disabled' : '') . '">'
            . '<a class="page-link" href="' . $href($cur - 1) . '" aria-label="' . e('list.prev_page') . '"><i class="bi bi-chevron-left"></i></a></li>';

        // หน้าต่างเลขหน้า (1 … cur±2 … last)
        foreach (guestPageWindow($cur, $pages) as $p) {
            if ($p === '…') {
                $h .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            } elseif ($p === $cur) {
                $h .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $p . '</span></li>';
            } else {
                $h .= '<li class="page-item"><a class="page-link" href="' . $href((int)$p) . '">' . $p . '</a></li>';
            }
        }

        // ถัดไป
        $h .= '<li class="page-item' . ($cur >= $pages ? ' disabled' : '') . '">'
            . '<a class="page-link" href="' . $href($cur + 1) . '" aria-label="' . e('list.next_page') . '"><i class="bi bi-chevron-right"></i></a></li>';

        $h .= '</ul>';

        // ช่องกรอก "ไปหน้า [ ] [ไป]" (นำทางด้วย JS)
        $h .= '<div class="gl-page-jump input-group input-group-sm">'
            . '<span class="input-group-text">' . e('list.go_to_page') . '</span>'
            . '<input type="text" class="form-control gl-page-input" inputmode="numeric" maxlength="7" value="' . $cur . '" data-max="' . $pages . '" aria-label="' . e('list.go_to_page') . '">'
            . '<button type="button" class="btn btn-outline-secondary gl-page-go">' . e('list.go') . '</button>'
            . '</div>';

        $h .= '</nav>';
        return $h;
    }
}
