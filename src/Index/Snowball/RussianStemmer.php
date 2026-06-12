<?php

namespace Tag1\Scolta\Index\Snowball;
// Generated from russian.sbl by Snowball 3.0.0 - https://snowballstem.org/

class RussianStemmer extends SnowballStemmer
{
    private const A_0 = [
        ["\u{0432}\u{0448}\u{0438}\u{0441}\u{044C}", -1, 1],
        ["\u{044B}\u{0432}\u{0448}\u{0438}\u{0441}\u{044C}", 0, 2],
        ["\u{0438}\u{0432}\u{0448}\u{0438}\u{0441}\u{044C}", 0, 2],
        ["\u{0432}", -1, 1],
        ["\u{044B}\u{0432}", 3, 2],
        ["\u{0438}\u{0432}", 3, 2],
        ["\u{0432}\u{0448}\u{0438}", -1, 1],
        ["\u{044B}\u{0432}\u{0448}\u{0438}", 6, 2],
        ["\u{0438}\u{0432}\u{0448}\u{0438}", 6, 2]
    ];

    private const A_1 = [
        ["\u{0435}\u{043C}\u{0443}", -1, 1],
        ["\u{043E}\u{043C}\u{0443}", -1, 1],
        ["\u{044B}\u{0445}", -1, 1],
        ["\u{0438}\u{0445}", -1, 1],
        ["\u{0443}\u{044E}", -1, 1],
        ["\u{044E}\u{044E}", -1, 1],
        ["\u{0435}\u{044E}", -1, 1],
        ["\u{043E}\u{044E}", -1, 1],
        ["\u{044F}\u{044F}", -1, 1],
        ["\u{0430}\u{044F}", -1, 1],
        ["\u{044B}\u{0435}", -1, 1],
        ["\u{0435}\u{0435}", -1, 1],
        ["\u{0438}\u{0435}", -1, 1],
        ["\u{043E}\u{0435}", -1, 1],
        ["\u{044B}\u{043C}\u{0438}", -1, 1],
        ["\u{0438}\u{043C}\u{0438}", -1, 1],
        ["\u{044B}\u{0439}", -1, 1],
        ["\u{0435}\u{0439}", -1, 1],
        ["\u{0438}\u{0439}", -1, 1],
        ["\u{043E}\u{0439}", -1, 1],
        ["\u{044B}\u{043C}", -1, 1],
        ["\u{0435}\u{043C}", -1, 1],
        ["\u{0438}\u{043C}", -1, 1],
        ["\u{043E}\u{043C}", -1, 1],
        ["\u{0435}\u{0433}\u{043E}", -1, 1],
        ["\u{043E}\u{0433}\u{043E}", -1, 1]
    ];

    private const A_2 = [
        ["\u{0432}\u{0448}", -1, 1],
        ["\u{044B}\u{0432}\u{0448}", 0, 2],
        ["\u{0438}\u{0432}\u{0448}", 0, 2],
        ["\u{0449}", -1, 1],
        ["\u{044E}\u{0449}", 3, 1],
        ["\u{0443}\u{044E}\u{0449}", 4, 2],
        ["\u{0435}\u{043C}", -1, 1],
        ["\u{043D}\u{043D}", -1, 1]
    ];

    private const A_3 = [
        ["\u{0441}\u{044C}", -1, 1],
        ["\u{0441}\u{044F}", -1, 1]
    ];

    private const A_4 = [
        ["\u{044B}\u{0442}", -1, 2],
        ["\u{044E}\u{0442}", -1, 1],
        ["\u{0443}\u{044E}\u{0442}", 1, 2],
        ["\u{044F}\u{0442}", -1, 2],
        ["\u{0435}\u{0442}", -1, 1],
        ["\u{0443}\u{0435}\u{0442}", 4, 2],
        ["\u{0438}\u{0442}", -1, 2],
        ["\u{043D}\u{044B}", -1, 1],
        ["\u{0435}\u{043D}\u{044B}", 7, 2],
        ["\u{0442}\u{044C}", -1, 1],
        ["\u{044B}\u{0442}\u{044C}", 9, 2],
        ["\u{0438}\u{0442}\u{044C}", 9, 2],
        ["\u{0435}\u{0448}\u{044C}", -1, 1],
        ["\u{0438}\u{0448}\u{044C}", -1, 2],
        ["\u{044E}", -1, 2],
        ["\u{0443}\u{044E}", 14, 2],
        ["\u{043B}\u{0430}", -1, 1],
        ["\u{044B}\u{043B}\u{0430}", 16, 2],
        ["\u{0438}\u{043B}\u{0430}", 16, 2],
        ["\u{043D}\u{0430}", -1, 1],
        ["\u{0435}\u{043D}\u{0430}", 19, 2],
        ["\u{0435}\u{0442}\u{0435}", -1, 1],
        ["\u{0438}\u{0442}\u{0435}", -1, 2],
        ["\u{0439}\u{0442}\u{0435}", -1, 1],
        ["\u{0443}\u{0439}\u{0442}\u{0435}", 23, 2],
        ["\u{0435}\u{0439}\u{0442}\u{0435}", 23, 2],
        ["\u{043B}\u{0438}", -1, 1],
        ["\u{044B}\u{043B}\u{0438}", 26, 2],
        ["\u{0438}\u{043B}\u{0438}", 26, 2],
        ["\u{0439}", -1, 1],
        ["\u{0443}\u{0439}", 29, 2],
        ["\u{0435}\u{0439}", 29, 2],
        ["\u{043B}", -1, 1],
        ["\u{044B}\u{043B}", 32, 2],
        ["\u{0438}\u{043B}", 32, 2],
        ["\u{044B}\u{043C}", -1, 2],
        ["\u{0435}\u{043C}", -1, 1],
        ["\u{0438}\u{043C}", -1, 2],
        ["\u{043D}", -1, 1],
        ["\u{0435}\u{043D}", 38, 2],
        ["\u{043B}\u{043E}", -1, 1],
        ["\u{044B}\u{043B}\u{043E}", 40, 2],
        ["\u{0438}\u{043B}\u{043E}", 40, 2],
        ["\u{043D}\u{043E}", -1, 1],
        ["\u{0435}\u{043D}\u{043E}", 43, 2],
        ["\u{043D}\u{043D}\u{043E}", 43, 1]
    ];

    private const A_5 = [
        ["\u{0443}", -1, 1],
        ["\u{044F}\u{0445}", -1, 1],
        ["\u{0438}\u{044F}\u{0445}", 1, 1],
        ["\u{0430}\u{0445}", -1, 1],
        ["\u{044B}", -1, 1],
        ["\u{044C}", -1, 1],
        ["\u{044E}", -1, 1],
        ["\u{044C}\u{044E}", 6, 1],
        ["\u{0438}\u{044E}", 6, 1],
        ["\u{044F}", -1, 1],
        ["\u{044C}\u{044F}", 9, 1],
        ["\u{0438}\u{044F}", 9, 1],
        ["\u{0430}", -1, 1],
        ["\u{0435}\u{0432}", -1, 1],
        ["\u{043E}\u{0432}", -1, 1],
        ["\u{0435}", -1, 1],
        ["\u{044C}\u{0435}", 15, 1],
        ["\u{0438}\u{0435}", 15, 1],
        ["\u{0438}", -1, 1],
        ["\u{0435}\u{0438}", 18, 1],
        ["\u{0438}\u{0438}", 18, 1],
        ["\u{044F}\u{043C}\u{0438}", 18, 1],
        ["\u{0438}\u{044F}\u{043C}\u{0438}", 21, 1],
        ["\u{0430}\u{043C}\u{0438}", 18, 1],
        ["\u{0439}", -1, 1],
        ["\u{0435}\u{0439}", 24, 1],
        ["\u{0438}\u{0435}\u{0439}", 25, 1],
        ["\u{0438}\u{0439}", 24, 1],
        ["\u{043E}\u{0439}", 24, 1],
        ["\u{044F}\u{043C}", -1, 1],
        ["\u{0438}\u{044F}\u{043C}", 29, 1],
        ["\u{0430}\u{043C}", -1, 1],
        ["\u{0435}\u{043C}", -1, 1],
        ["\u{0438}\u{0435}\u{043C}", 32, 1],
        ["\u{043E}\u{043C}", -1, 1],
        ["\u{043E}", -1, 1]
    ];

    private const A_6 = [
        ["\u{043E}\u{0441}\u{0442}", -1, 1],
        ["\u{043E}\u{0441}\u{0442}\u{044C}", -1, 1]
    ];

    private const A_7 = [
        ["\u{0435}\u{0439}\u{0448}", -1, 1],
        ["\u{044C}", -1, 3],
        ["\u{0435}\u{0439}\u{0448}\u{0435}", -1, 1],
        ["\u{043D}", -1, 2]
    ];

    private const G_v = ["\u{0430}"=>true, "\u{0435}"=>true, "\u{0438}"=>true, "\u{043E}"=>true, "\u{0443}"=>true, "\u{044B}"=>true, "\u{044D}"=>true, "\u{044E}"=>true, "\u{044F}"=>true];

    private int $I_p2 = 0;
    private int $I_pV = 0;



    protected function r_mark_regions(): bool
    {
        $this->I_pV = $this->limit;
        $this->I_p2 = $this->limit;
        $v_1 = $this->cursor;
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
        $this->I_pV = $this->cursor;
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
        $this->I_p2 = $this->cursor;
    lab0:
        $this->cursor = $v_1;
        return true;
    }


    protected function r_R2(): bool
    {
        return $this->I_p2 <= $this->cursor;
    }


    protected function r_perfective_gerund(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_0);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $v_1 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("\u{0430}"))) {
                    goto lab0;
                }
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_1;
                if (!($this->eq_s_b("\u{044F}"))) {
                    return false;
                }
            lab1:
                $this->slice_del();
                break;
            case 2:
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_adjective(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_1) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        return true;
    }


    protected function r_adjectival(): bool
    {
        if (!$this->r_adjective()) {
            return false;
        }
        $v_1 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_2);
        if (0 === $among_var) {
            $this->cursor = $this->limit - $v_1;
            goto lab0;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $v_2 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("\u{0430}"))) {
                    goto lab1;
                }
                goto lab2;
            lab1:
                $this->cursor = $this->limit - $v_2;
                if (!($this->eq_s_b("\u{044F}"))) {
                    $this->cursor = $this->limit - $v_1;
                    goto lab0;
                }
            lab2:
                $this->slice_del();
                break;
            case 2:
                $this->slice_del();
                break;
        }
    lab0:
        return true;
    }


    protected function r_reflexive(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_3) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        return true;
    }


    protected function r_verb(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_4);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $v_1 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("\u{0430}"))) {
                    goto lab0;
                }
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_1;
                if (!($this->eq_s_b("\u{044F}"))) {
                    return false;
                }
            lab1:
                $this->slice_del();
                break;
            case 2:
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_noun(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_5) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        return true;
    }


    protected function r_derivational(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_6) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R2()) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_tidy_up(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_7);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_del();
                $this->ket = $this->cursor;
                if (!($this->eq_s_b("\u{043D}"))) {
                    return false;
                }
                $this->bra = $this->cursor;
                if (!($this->eq_s_b("\u{043D}"))) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if (!($this->eq_s_b("\u{043D}"))) {
                    return false;
                }
                $this->slice_del();
                break;
            case 3:
                $this->slice_del();
                break;
        }
        return true;
    }


    public function stem(): bool
    {
        $v_1 = $this->cursor;
        while (true) {
            $v_2 = $this->cursor;
            while (true) {
                $v_3 = $this->cursor;
                $this->bra = $this->cursor;
                if (!($this->eq_s("\u{0451}"))) {
                    goto lab2;
                }
                $this->ket = $this->cursor;
                $this->cursor = $v_3;
                break;
            lab2:
                $this->cursor = $v_3;
                if ($this->cursor >= $this->limit) {
                    goto lab1;
                }
                $this->inc_cursor();
            }
            $this->slice_from("\u{0435}");
            continue;
        lab1:
            $this->cursor = $v_2;
            break;
        }
    lab0:
        $this->cursor = $v_1;
        $this->r_mark_regions();
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        if ($this->cursor < $this->I_pV) {
            return false;
        }
        $v_4 = $this->limit_backward;
        $this->limit_backward = $this->I_pV;
        $v_5 = $this->limit - $this->cursor;
        $v_6 = $this->limit - $this->cursor;
        if (!$this->r_perfective_gerund()) {
            goto lab4;
        }
        goto lab5;
    lab4:
        $this->cursor = $this->limit - $v_6;
        $v_7 = $this->limit - $this->cursor;
        if (!$this->r_reflexive()) {
            $this->cursor = $this->limit - $v_7;
            goto lab6;
        }
    lab6:
        $v_8 = $this->limit - $this->cursor;
        if (!$this->r_adjectival()) {
            goto lab7;
        }
        goto lab8;
    lab7:
        $this->cursor = $this->limit - $v_8;
        if (!$this->r_verb()) {
            goto lab9;
        }
        goto lab8;
    lab9:
        $this->cursor = $this->limit - $v_8;
        if (!$this->r_noun()) {
            goto lab3;
        }
    lab8:
    lab5:
    lab3:
        $this->cursor = $this->limit - $v_5;
        $v_9 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{0438}"))) {
            $this->cursor = $this->limit - $v_9;
            goto lab10;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
    lab10:
        $v_10 = $this->limit - $this->cursor;
        $this->r_derivational();
        $this->cursor = $this->limit - $v_10;
        $v_11 = $this->limit - $this->cursor;
        $this->r_tidy_up();
        $this->cursor = $this->limit - $v_11;
        $this->limit_backward = $v_4;
        $this->cursor = $this->limit_backward;
        return true;
    }
}
