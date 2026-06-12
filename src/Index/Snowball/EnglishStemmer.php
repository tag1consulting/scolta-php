<?php

namespace Tag1\Scolta\Index\Snowball;
// Generated from english.sbl by Snowball 3.0.0 - https://snowballstem.org/

class EnglishStemmer extends SnowballStemmer
{
    private const A_0 = [
        ["arsen", -1, -1],
        ["commun", -1, -1],
        ["emerg", -1, -1],
        ["gener", -1, -1],
        ["inter", -1, -1],
        ["later", -1, -1],
        ["organ", -1, -1],
        ["past", -1, -1],
        ["univers", -1, -1]
    ];

    private const A_1 = [
        ["'", -1, 1],
        ["'s'", 0, 1],
        ["'s", -1, 1]
    ];

    private const A_2 = [
        ["ied", -1, 2],
        ["s", -1, 3],
        ["ies", 1, 2],
        ["sses", 1, 1],
        ["ss", 1, -1],
        ["us", 1, -1]
    ];

    private const A_3 = [
        ["succ", -1, 1],
        ["proc", -1, 1],
        ["exc", -1, 1]
    ];

    private const A_4 = [
        ["even", -1, 2],
        ["cann", -1, 2],
        ["inn", -1, 2],
        ["earr", -1, 2],
        ["herr", -1, 2],
        ["out", -1, 2],
        ["y", -1, 1]
    ];

    private const A_5 = [
        ["", -1, -1],
        ["ed", 0, 2],
        ["eed", 1, 1],
        ["ing", 0, 3],
        ["edly", 0, 2],
        ["eedly", 4, 1],
        ["ingly", 0, 2]
    ];

    private const A_6 = [
        ["", -1, 3],
        ["bb", 0, 2],
        ["dd", 0, 2],
        ["ff", 0, 2],
        ["gg", 0, 2],
        ["bl", 0, 1],
        ["mm", 0, 2],
        ["nn", 0, 2],
        ["pp", 0, 2],
        ["rr", 0, 2],
        ["at", 0, 1],
        ["tt", 0, 2],
        ["iz", 0, 1]
    ];

    private const A_7 = [
        ["anci", -1, 3],
        ["enci", -1, 2],
        ["ogi", -1, 14],
        ["li", -1, 16],
        ["bli", 3, 12],
        ["abli", 4, 4],
        ["alli", 3, 8],
        ["fulli", 3, 9],
        ["lessli", 3, 15],
        ["ousli", 3, 10],
        ["entli", 3, 5],
        ["aliti", -1, 8],
        ["biliti", -1, 12],
        ["iviti", -1, 11],
        ["tional", -1, 1],
        ["ational", 14, 7],
        ["alism", -1, 8],
        ["ation", -1, 7],
        ["ization", 17, 6],
        ["izer", -1, 6],
        ["ator", -1, 7],
        ["iveness", -1, 11],
        ["fulness", -1, 9],
        ["ousness", -1, 10],
        ["ogist", -1, 13]
    ];

    private const A_8 = [
        ["icate", -1, 4],
        ["ative", -1, 6],
        ["alize", -1, 3],
        ["iciti", -1, 4],
        ["ical", -1, 4],
        ["tional", -1, 1],
        ["ational", 5, 2],
        ["ful", -1, 5],
        ["ness", -1, 5]
    ];

    private const A_9 = [
        ["ic", -1, 1],
        ["ance", -1, 1],
        ["ence", -1, 1],
        ["able", -1, 1],
        ["ible", -1, 1],
        ["ate", -1, 1],
        ["ive", -1, 1],
        ["ize", -1, 1],
        ["iti", -1, 1],
        ["al", -1, 1],
        ["ism", -1, 1],
        ["ion", -1, 2],
        ["er", -1, 1],
        ["ous", -1, 1],
        ["ant", -1, 1],
        ["ent", -1, 1],
        ["ment", 15, 1],
        ["ement", 16, 1]
    ];

    private const A_10 = [
        ["e", -1, 1],
        ["l", -1, 2]
    ];

    private const A_11 = [
        ["andes", -1, -1],
        ["atlas", -1, -1],
        ["bias", -1, -1],
        ["cosmos", -1, -1],
        ["early", -1, 6],
        ["gently", -1, 4],
        ["howe", -1, -1],
        ["idly", -1, 3],
        ["news", -1, -1],
        ["only", -1, 7],
        ["singly", -1, 8],
        ["skies", -1, 2],
        ["skis", -1, 1],
        ["sky", -1, -1],
        ["ugly", -1, 5]
    ];

    private const G_aeo = ["a"=>true, "e"=>true, "o"=>true];

    private const G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "y"=>true];

    private const G_v_WXY = ["Y"=>true, "a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "w"=>true, "x"=>true, "y"=>true];

    private const G_valid_LI = ["c"=>true, "d"=>true, "e"=>true, "g"=>true, "h"=>true, "k"=>true, "m"=>true, "n"=>true, "r"=>true, "t"=>true];

    private bool $B_Y_found = false;
    private int $I_p2 = 0;
    private int $I_p1 = 0;



    protected function r_prelude(): bool
    {
        $this->B_Y_found = false;
        $v_1 = $this->cursor;
        $this->bra = $this->cursor;
        if (!($this->eq_s("'"))) {
            goto lab0;
        }
        $this->ket = $this->cursor;
        $this->slice_del();
    lab0:
        $this->cursor = $v_1;
        $v_2 = $this->cursor;
        $this->bra = $this->cursor;
        if (!($this->eq_s("y"))) {
            goto lab1;
        }
        $this->ket = $this->cursor;
        $this->slice_from("Y");
        $this->B_Y_found = true;
    lab1:
        $this->cursor = $v_2;
        $v_3 = $this->cursor;
        while (true) {
            $v_4 = $this->cursor;
            while (true) {
                $v_5 = $this->cursor;
                if (!($this->in_grouping(self::G_v))) {
                    goto lab4;
                }
                $this->bra = $this->cursor;
                if (!($this->eq_s("y"))) {
                    goto lab4;
                }
                $this->ket = $this->cursor;
                $this->cursor = $v_5;
                break;
            lab4:
                $this->cursor = $v_5;
                if ($this->cursor >= $this->limit) {
                    goto lab3;
                }
                $this->inc_cursor();
            }
            $this->slice_from("Y");
            $this->B_Y_found = true;
            continue;
        lab3:
            $this->cursor = $v_4;
            break;
        }
    lab2:
        $this->cursor = $v_3;
        return true;
    }


    protected function r_mark_regions(): bool
    {
        $this->I_p1 = $this->limit;
        $this->I_p2 = $this->limit;
        $v_1 = $this->cursor;
        $v_2 = $this->cursor;
        if ($this->find_among(self::A_0) === 0) {
            goto lab1;
        }
        goto lab2;
    lab1:
        $this->cursor = $v_2;
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
    lab2:
        $this->I_p1 = $this->cursor;
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


    protected function r_shortv(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        if (!($this->out_grouping_b(self::G_v_WXY))) {
            goto lab0;
        }
        if (!($this->in_grouping_b(self::G_v))) {
            goto lab0;
        }
        if (!($this->out_grouping_b(self::G_v))) {
            goto lab0;
        }
        goto lab1;
    lab0:
        $this->cursor = $this->limit - $v_1;
        if (!($this->out_grouping_b(self::G_v))) {
            goto lab2;
        }
        if (!($this->in_grouping_b(self::G_v))) {
            goto lab2;
        }
        if ($this->cursor > $this->limit_backward) {
            goto lab2;
        }
        goto lab1;
    lab2:
        $this->cursor = $this->limit - $v_1;
        if (!($this->eq_s_b("past"))) {
            return false;
        }
    lab1:
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


    protected function r_Step_1a(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_1) === 0) {
            $this->cursor = $this->limit - $v_1;
            goto lab0;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
    lab0:
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_2);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_from("ss");
                break;
            case 2:
                $v_2 = $this->limit - $this->cursor;
                if (!$this->hop_back(2)) {
                    goto lab1;
                }
                $this->slice_from("i");
                goto lab2;
            lab1:
                $this->cursor = $this->limit - $v_2;
                $this->slice_from("ie");
            lab2:
                break;
            case 3:
                if ($this->cursor <= $this->limit_backward) {
                    return false;
                }
                $this->dec_cursor();
                if (!$this->go_out_grouping_b(self::G_v)) {
                    return false;
                }
                $this->dec_cursor();
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Step_1b(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_5);
        $this->bra = $this->cursor;
        $v_1 = $this->limit - $this->cursor;
        switch ($among_var) {
            case 1:
                $v_2 = $this->limit - $this->cursor;
                if (!$this->r_R1()) {
                    goto lab1;
                }
                $v_3 = $this->limit - $this->cursor;
                if ($this->find_among_b(self::A_3) === 0) {
                    goto lab2;
                }
                if ($this->cursor > $this->limit_backward) {
                    goto lab2;
                }
                goto lab3;
            lab2:
                $this->cursor = $this->limit - $v_3;
                $this->slice_from("ee");
            lab3:
            lab1:
                $this->cursor = $this->limit - $v_2;
                break;
            case 2:
                goto lab0;
            case 3:
                $among_var = $this->find_among_b(self::A_4);
                if (0 === $among_var) {
                    goto lab0;
                }
                switch ($among_var) {
                    case 1:
                        $v_4 = $this->limit - $this->cursor;
                        if (!($this->out_grouping_b(self::G_v))) {
                            goto lab0;
                        }
                        if ($this->cursor > $this->limit_backward) {
                            goto lab0;
                        }
                        $this->cursor = $this->limit - $v_4;
                        $this->bra = $this->cursor;
                        $this->slice_from("ie");
                        break;
                    case 2:
                        if ($this->cursor > $this->limit_backward) {
                            goto lab0;
                        }
                        break;
                }
                break;
        }
        goto lab4;
    lab0:
        $this->cursor = $this->limit - $v_1;
        $v_5 = $this->limit - $this->cursor;
        if (!$this->go_out_grouping_b(self::G_v)) {
            return false;
        }
        $this->dec_cursor();
        $this->cursor = $this->limit - $v_5;
        $this->slice_del();
        $this->ket = $this->cursor;
        $this->bra = $this->cursor;
        $v_6 = $this->limit - $this->cursor;
        $among_var = $this->find_among_b(self::A_6);
        switch ($among_var) {
            case 1:
                $this->slice_from("e");
                return false;
            case 2:
                $v_7 = $this->limit - $this->cursor;
                if (!($this->in_grouping_b(self::G_aeo))) {
                    goto lab5;
                }
                if ($this->cursor > $this->limit_backward) {
                    goto lab5;
                }
                return false;
            lab5:
                $this->cursor = $this->limit - $v_7;
                break;
            case 3:
                if ($this->cursor !== $this->I_p1) {
                    return false;
                }
                $v_8 = $this->limit - $this->cursor;
                if (!$this->r_shortv()) {
                    return false;
                }
                $this->cursor = $this->limit - $v_8;
                $this->slice_from("e");
                return false;
        }
        $this->cursor = $this->limit - $v_6;
        $this->ket = $this->cursor;
        if ($this->cursor <= $this->limit_backward) {
            return false;
        }
        $this->dec_cursor();
        $this->bra = $this->cursor;
        $this->slice_del();
    lab4:
        return true;
    }


    protected function r_Step_1c(): bool
    {
        $this->ket = $this->cursor;
        $v_1 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("y"))) {
            goto lab0;
        }
        goto lab1;
    lab0:
        $this->cursor = $this->limit - $v_1;
        if (!($this->eq_s_b("Y"))) {
            return false;
        }
    lab1:
        $this->bra = $this->cursor;
        if (!($this->out_grouping_b(self::G_v))) {
            return false;
        }
        if ($this->cursor > $this->limit_backward) {
            goto lab2;
        }
        return false;
    lab2:
        $this->slice_from("i");
        return true;
    }


    protected function r_Step_2(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_7);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_from("tion");
                break;
            case 2:
                $this->slice_from("ence");
                break;
            case 3:
                $this->slice_from("ance");
                break;
            case 4:
                $this->slice_from("able");
                break;
            case 5:
                $this->slice_from("ent");
                break;
            case 6:
                $this->slice_from("ize");
                break;
            case 7:
                $this->slice_from("ate");
                break;
            case 8:
                $this->slice_from("al");
                break;
            case 9:
                $this->slice_from("ful");
                break;
            case 10:
                $this->slice_from("ous");
                break;
            case 11:
                $this->slice_from("ive");
                break;
            case 12:
                $this->slice_from("ble");
                break;
            case 13:
                $this->slice_from("og");
                break;
            case 14:
                if (!($this->eq_s_b("l"))) {
                    return false;
                }
                $this->slice_from("og");
                break;
            case 15:
                $this->slice_from("less");
                break;
            case 16:
                if (!($this->in_grouping_b(self::G_valid_LI))) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Step_3(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_8);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_from("tion");
                break;
            case 2:
                $this->slice_from("ate");
                break;
            case 3:
                $this->slice_from("al");
                break;
            case 4:
                $this->slice_from("ic");
                break;
            case 5:
                $this->slice_del();
                break;
            case 6:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Step_4(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_9);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R2()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $v_1 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("s"))) {
                    goto lab0;
                }
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_1;
                if (!($this->eq_s_b("t"))) {
                    return false;
                }
            lab1:
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Step_5(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_10);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_R2()) {
                    goto lab0;
                }
                goto lab1;
            lab0:
                if (!$this->r_R1()) {
                    return false;
                }
                $v_1 = $this->limit - $this->cursor;
                if (!$this->r_shortv()) {
                    goto lab2;
                }
                return false;
            lab2:
                $this->cursor = $this->limit - $v_1;
            lab1:
                $this->slice_del();
                break;
            case 2:
                if (!$this->r_R2()) {
                    return false;
                }
                if (!($this->eq_s_b("l"))) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_exception1(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_11);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        if ($this->cursor < $this->limit) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_from("ski");
                break;
            case 2:
                $this->slice_from("sky");
                break;
            case 3:
                $this->slice_from("idl");
                break;
            case 4:
                $this->slice_from("gentl");
                break;
            case 5:
                $this->slice_from("ugli");
                break;
            case 6:
                $this->slice_from("earli");
                break;
            case 7:
                $this->slice_from("onli");
                break;
            case 8:
                $this->slice_from("singl");
                break;
        }
        return true;
    }


    protected function r_postlude(): bool
    {
        if (!$this->B_Y_found) {
            return false;
        }
        while (true) {
            $v_1 = $this->cursor;
            while (true) {
                $v_2 = $this->cursor;
                $this->bra = $this->cursor;
                if (!($this->eq_s("Y"))) {
                    goto lab1;
                }
                $this->ket = $this->cursor;
                $this->cursor = $v_2;
                break;
            lab1:
                $this->cursor = $v_2;
                if ($this->cursor >= $this->limit) {
                    goto lab0;
                }
                $this->inc_cursor();
            }
            $this->slice_from("y");
            continue;
        lab0:
            $this->cursor = $v_1;
            break;
        }
        return true;
    }


    public function stem(): bool
    {
        $v_1 = $this->cursor;
        if (!$this->r_exception1()) {
            goto lab0;
        }
        goto lab1;
    lab0:
        $this->cursor = $v_1;
        $v_2 = $this->cursor;
        if (!$this->hop(3)) {
            goto lab3;
        }
        goto lab2;
    lab3:
        $this->cursor = $v_2;
        goto lab1;
    lab2:
        $this->cursor = $v_1;
        $this->r_prelude();
        $this->r_mark_regions();
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_3 = $this->limit - $this->cursor;
        $this->r_Step_1a();
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        $this->r_Step_1b();
        $this->cursor = $this->limit - $v_4;
        $v_5 = $this->limit - $this->cursor;
        $this->r_Step_1c();
        $this->cursor = $this->limit - $v_5;
        $v_6 = $this->limit - $this->cursor;
        $this->r_Step_2();
        $this->cursor = $this->limit - $v_6;
        $v_7 = $this->limit - $this->cursor;
        $this->r_Step_3();
        $this->cursor = $this->limit - $v_7;
        $v_8 = $this->limit - $this->cursor;
        $this->r_Step_4();
        $this->cursor = $this->limit - $v_8;
        $v_9 = $this->limit - $this->cursor;
        $this->r_Step_5();
        $this->cursor = $this->limit - $v_9;
        $this->cursor = $this->limit_backward;
        $v_10 = $this->cursor;
        $this->r_postlude();
        $this->cursor = $v_10;
    lab1:
        return true;
    }
}
