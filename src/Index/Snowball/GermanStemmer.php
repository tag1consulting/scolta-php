<?php

namespace Tag1\Scolta\Index\Snowball;
// Generated from german.sbl by Snowball 3.0.0 - https://snowballstem.org/

class GermanStemmer extends SnowballStemmer
{
    private const A_0 = [
        ["", -1, 5],
        ["ae", 0, 2],
        ["oe", 0, 3],
        ["qu", 0, -1],
        ["ue", 0, 4],
        ["\u{00DF}", 0, 1]
    ];

    private const A_1 = [
        ["", -1, 5],
        ["U", 0, 2],
        ["Y", 0, 1],
        ["\u{00E4}", 0, 3],
        ["\u{00F6}", 0, 4],
        ["\u{00FC}", 0, 2]
    ];

    private const A_2 = [
        ["e", -1, 3],
        ["em", -1, 1],
        ["en", -1, 3],
        ["erinnen", 2, 2],
        ["erin", -1, 2],
        ["ln", -1, 5],
        ["ern", -1, 2],
        ["er", -1, 2],
        ["s", -1, 4],
        ["es", 8, 3],
        ["lns", 8, 5]
    ];

    private const A_3 = [
        ["tick", -1, -1],
        ["plan", -1, -1],
        ["geordn", -1, -1],
        ["intern", -1, -1],
        ["tr", -1, -1]
    ];

    private const A_4 = [
        ["en", -1, 1],
        ["er", -1, 1],
        ["et", -1, 3],
        ["st", -1, 2],
        ["est", 3, 1]
    ];

    private const A_5 = [
        ["ig", -1, 1],
        ["lich", -1, 1]
    ];

    private const A_6 = [
        ["end", -1, 1],
        ["ig", -1, 2],
        ["ung", -1, 1],
        ["lich", -1, 3],
        ["isch", -1, 2],
        ["ik", -1, 2],
        ["heit", -1, 3],
        ["keit", -1, 4]
    ];

    private const G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "y"=>true, "\u{00E4}"=>true, "\u{00F6}"=>true, "\u{00FC}"=>true];

    private const G_et_ending = ["U"=>true, "d"=>true, "f"=>true, "g"=>true, "k"=>true, "l"=>true, "m"=>true, "n"=>true, "r"=>true, "s"=>true, "t"=>true, "z"=>true, "\u{00E4}"=>true];

    private const G_s_ending = ["b"=>true, "d"=>true, "f"=>true, "g"=>true, "h"=>true, "k"=>true, "l"=>true, "m"=>true, "n"=>true, "r"=>true, "t"=>true];

    private const G_st_ending = ["b"=>true, "d"=>true, "f"=>true, "g"=>true, "h"=>true, "k"=>true, "l"=>true, "m"=>true, "n"=>true, "t"=>true];

    private int $I_p2 = 0;
    private int $I_p1 = 0;



    protected function r_prelude(): bool
    {
        $v_1 = $this->cursor;
        while (true) {
            $v_2 = $this->cursor;
            while (true) {
                $v_3 = $this->cursor;
                if (!($this->in_grouping(self::G_v))) {
                    goto lab1;
                }
                $this->bra = $this->cursor;
                $v_4 = $this->cursor;
                if (!($this->eq_s("u"))) {
                    goto lab2;
                }
                $this->ket = $this->cursor;
                if (!($this->in_grouping(self::G_v))) {
                    goto lab2;
                }
                $this->slice_from("U");
                goto lab3;
            lab2:
                $this->cursor = $v_4;
                if (!($this->eq_s("y"))) {
                    goto lab1;
                }
                $this->ket = $this->cursor;
                if (!($this->in_grouping(self::G_v))) {
                    goto lab1;
                }
                $this->slice_from("Y");
            lab3:
                $this->cursor = $v_3;
                break;
            lab1:
                $this->cursor = $v_3;
                if ($this->cursor >= $this->limit) {
                    goto lab0;
                }
                $this->inc_cursor();
            }
            continue;
        lab0:
            $this->cursor = $v_2;
            break;
        }
        $this->cursor = $v_1;
        while (true) {
            $v_5 = $this->cursor;
            $this->bra = $this->cursor;
            $among_var = $this->find_among(self::A_0);
            $this->ket = $this->cursor;
            switch ($among_var) {
                case 1:
                    $this->slice_from("ss");
                    break;
                case 2:
                    $this->slice_from("\u{00E4}");
                    break;
                case 3:
                    $this->slice_from("\u{00F6}");
                    break;
                case 4:
                    $this->slice_from("\u{00FC}");
                    break;
                case 5:
                    if ($this->cursor >= $this->limit) {
                        goto lab4;
                    }
                    $this->inc_cursor();
                    break;
            }
            continue;
        lab4:
            $this->cursor = $v_5;
            break;
        }
        return true;
    }


    protected function r_mark_regions(): bool
    {
        $this->I_p1 = $this->limit;
        $this->I_p2 = $this->limit;
        $v_1 = $this->cursor;
        if (!$this->hop(3)) {
            return false;
        }
        $I_x = $this->cursor;
        $this->cursor = $v_1;
        if (!$this->go_out_grouping(self::G_v)) {
            return false;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            return false;
        }
        $this->inc_cursor();
        $this->I_p1 = $this->cursor;
        if ($this->I_p1 >= $I_x) {
            goto lab0;
        }
        $this->I_p1 = $I_x;
    lab0:
        if (!$this->go_out_grouping(self::G_v)) {
            return false;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            return false;
        }
        $this->inc_cursor();
        $this->I_p2 = $this->cursor;
        return true;
    }


    protected function r_postlude(): bool
    {
        while (true) {
            $v_1 = $this->cursor;
            $this->bra = $this->cursor;
            $among_var = $this->find_among(self::A_1);
            $this->ket = $this->cursor;
            switch ($among_var) {
                case 1:
                    $this->slice_from("y");
                    break;
                case 2:
                    $this->slice_from("u");
                    break;
                case 3:
                    $this->slice_from("a");
                    break;
                case 4:
                    $this->slice_from("o");
                    break;
                case 5:
                    if ($this->cursor >= $this->limit) {
                        goto lab0;
                    }
                    $this->inc_cursor();
                    break;
            }
            continue;
        lab0:
            $this->cursor = $v_1;
            break;
        }
        return true;
    }


    protected function r_R1(): bool
    {
        return $this->I_p1 <= $this->cursor;
    }


    protected function r_R2(): bool
    {
        return $this->I_p2 <= $this->cursor;
    }


    protected function r_standard_suffix(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_2);
        if (0 === $among_var) {
            goto lab0;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            goto lab0;
        }
        switch ($among_var) {
            case 1:
                $v_2 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("syst"))) {
                    goto lab1;
                }
                goto lab0;
            lab1:
                $this->cursor = $this->limit - $v_2;
                $this->slice_del();
                break;
            case 2:
                $this->slice_del();
                break;
            case 3:
                $this->slice_del();
                $v_3 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                if (!($this->eq_s_b("s"))) {
                    $this->cursor = $this->limit - $v_3;
                    goto lab2;
                }
                $this->bra = $this->cursor;
                if (!($this->eq_s_b("nis"))) {
                    $this->cursor = $this->limit - $v_3;
                    goto lab2;
                }
                $this->slice_del();
            lab2:
                break;
            case 4:
                if (!($this->in_grouping_b(self::G_s_ending))) {
                    goto lab0;
                }
                $this->slice_del();
                break;
            case 5:
                $this->slice_from("l");
                break;
        }
    lab0:
        $this->cursor = $this->limit - $v_1;
        $v_4 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_4);
        if (0 === $among_var) {
            goto lab3;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            goto lab3;
        }
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                if (!($this->in_grouping_b(self::G_st_ending))) {
                    goto lab3;
                }
                if (!$this->hop_back(3)) {
                    goto lab3;
                }
                $this->slice_del();
                break;
            case 3:
                $v_5 = $this->limit - $this->cursor;
                if (!($this->in_grouping_b(self::G_et_ending))) {
                    goto lab3;
                }
                $this->cursor = $this->limit - $v_5;
                $v_6 = $this->limit - $this->cursor;
                if ($this->find_among_b(self::A_3) === 0) {
                    goto lab4;
                }
                goto lab3;
            lab4:
                $this->cursor = $this->limit - $v_6;
                $this->slice_del();
                break;
        }
    lab3:
        $this->cursor = $this->limit - $v_4;
        $v_7 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_6);
        if (0 === $among_var) {
            goto lab5;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R2()) {
            goto lab5;
        }
        switch ($among_var) {
            case 1:
                $this->slice_del();
                $v_8 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                if (!($this->eq_s_b("ig"))) {
                    $this->cursor = $this->limit - $v_8;
                    goto lab6;
                }
                $this->bra = $this->cursor;
                $v_9 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("e"))) {
                    goto lab7;
                }
                $this->cursor = $this->limit - $v_8;
                goto lab6;
            lab7:
                $this->cursor = $this->limit - $v_9;
                if (!$this->r_R2()) {
                    $this->cursor = $this->limit - $v_8;
                    goto lab6;
                }
                $this->slice_del();
            lab6:
                break;
            case 2:
                $v_10 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("e"))) {
                    goto lab8;
                }
                goto lab5;
            lab8:
                $this->cursor = $this->limit - $v_10;
                $this->slice_del();
                break;
            case 3:
                $this->slice_del();
                $v_11 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                $v_12 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("er"))) {
                    goto lab10;
                }
                goto lab11;
            lab10:
                $this->cursor = $this->limit - $v_12;
                if (!($this->eq_s_b("en"))) {
                    $this->cursor = $this->limit - $v_11;
                    goto lab9;
                }
            lab11:
                $this->bra = $this->cursor;
                if (!$this->r_R1()) {
                    $this->cursor = $this->limit - $v_11;
                    goto lab9;
                }
                $this->slice_del();
            lab9:
                break;
            case 4:
                $this->slice_del();
                $v_13 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                if ($this->find_among_b(self::A_5) === 0) {
                    $this->cursor = $this->limit - $v_13;
                    goto lab12;
                }
                $this->bra = $this->cursor;
                if (!$this->r_R2()) {
                    $this->cursor = $this->limit - $v_13;
                    goto lab12;
                }
                $this->slice_del();
            lab12:
                break;
        }
    lab5:
        $this->cursor = $this->limit - $v_7;
        return true;
    }


    public function stem(): bool
    {
        $v_1 = $this->cursor;
        $this->r_prelude();
        $this->cursor = $v_1;
        $v_2 = $this->cursor;
        $this->r_mark_regions();
        $this->cursor = $v_2;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $this->r_standard_suffix();
        $this->cursor = $this->limit_backward;
        $v_3 = $this->cursor;
        $this->r_postlude();
        $this->cursor = $v_3;
        return true;
    }
}
