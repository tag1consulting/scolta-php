<?php

namespace Tag1\Scolta\Index\Snowball;
// Generated from finnish.sbl by Snowball 3.0.0 - https://snowballstem.org/

class FinnishStemmer extends SnowballStemmer
{
    private const A_0 = [
        ["pa", -1, 1],
        ["sti", -1, 2],
        ["kaan", -1, 1],
        ["han", -1, 1],
        ["kin", -1, 1],
        ["h\u{00E4}n", -1, 1],
        ["k\u{00E4}\u{00E4}n", -1, 1],
        ["ko", -1, 1],
        ["p\u{00E4}", -1, 1],
        ["k\u{00F6}", -1, 1]
    ];

    private const A_1 = [
        ["lla", -1, -1],
        ["na", -1, -1],
        ["ssa", -1, -1],
        ["ta", -1, -1],
        ["lta", 3, -1],
        ["sta", 3, -1]
    ];

    private const A_2 = [
        ["ll\u{00E4}", -1, -1],
        ["n\u{00E4}", -1, -1],
        ["ss\u{00E4}", -1, -1],
        ["t\u{00E4}", -1, -1],
        ["lt\u{00E4}", 3, -1],
        ["st\u{00E4}", 3, -1]
    ];

    private const A_3 = [
        ["lle", -1, -1],
        ["ine", -1, -1]
    ];

    private const A_4 = [
        ["nsa", -1, 3],
        ["mme", -1, 3],
        ["nne", -1, 3],
        ["ni", -1, 2],
        ["si", -1, 1],
        ["an", -1, 4],
        ["en", -1, 6],
        ["\u{00E4}n", -1, 5],
        ["ns\u{00E4}", -1, 3]
    ];

    private const A_5 = [
        ["aa", -1, -1],
        ["ee", -1, -1],
        ["ii", -1, -1],
        ["oo", -1, -1],
        ["uu", -1, -1],
        ["\u{00E4}\u{00E4}", -1, -1],
        ["\u{00F6}\u{00F6}", -1, -1]
    ];

    private const A_6 = [
        ["a", -1, 8],
        ["lla", 0, -1],
        ["na", 0, -1],
        ["ssa", 0, -1],
        ["ta", 0, -1],
        ["lta", 4, -1],
        ["sta", 4, -1],
        ["tta", 4, 2],
        ["lle", -1, -1],
        ["ine", -1, -1],
        ["ksi", -1, -1],
        ["n", -1, 7],
        ["han", 11, 1],
        ["den", 11, -1, 'r_VI'],
        ["seen", 11, -1, 'r_LONG'],
        ["hen", 11, 2],
        ["tten", 11, -1, 'r_VI'],
        ["hin", 11, 3],
        ["siin", 11, -1, 'r_VI'],
        ["hon", 11, 4],
        ["h\u{00E4}n", 11, 5],
        ["h\u{00F6}n", 11, 6],
        ["\u{00E4}", -1, 8],
        ["ll\u{00E4}", 22, -1],
        ["n\u{00E4}", 22, -1],
        ["ss\u{00E4}", 22, -1],
        ["t\u{00E4}", 22, -1],
        ["lt\u{00E4}", 26, -1],
        ["st\u{00E4}", 26, -1],
        ["tt\u{00E4}", 26, 2]
    ];

    private const A_7 = [
        ["eja", -1, -1],
        ["mma", -1, 1],
        ["imma", 1, -1],
        ["mpa", -1, 1],
        ["impa", 3, -1],
        ["mmi", -1, 1],
        ["immi", 5, -1],
        ["mpi", -1, 1],
        ["impi", 7, -1],
        ["ej\u{00E4}", -1, -1],
        ["mm\u{00E4}", -1, 1],
        ["imm\u{00E4}", 10, -1],
        ["mp\u{00E4}", -1, 1],
        ["imp\u{00E4}", 12, -1]
    ];

    private const A_8 = [
        ["i", -1, -1],
        ["j", -1, -1]
    ];

    private const A_9 = [
        ["mma", -1, 1],
        ["imma", 0, -1]
    ];

    private const G_AEI = ["a"=>true, "e"=>true, "i"=>true, "\u{00E4}"=>true];

    private const G_C = ["b"=>true, "c"=>true, "d"=>true, "f"=>true, "g"=>true, "h"=>true, "j"=>true, "k"=>true, "l"=>true, "m"=>true, "n"=>true, "p"=>true, "q"=>true, "r"=>true, "s"=>true, "t"=>true, "v"=>true, "w"=>true, "x"=>true, "z"=>true];

    private const G_V1 = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "y"=>true, "\u{00E4}"=>true, "\u{00F6}"=>true];

    private const G_V2 = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "\u{00E4}"=>true, "\u{00F6}"=>true];

    private const G_particle_end = ["a"=>true, "e"=>true, "i"=>true, "n"=>true, "o"=>true, "t"=>true, "u"=>true, "y"=>true, "\u{00E4}"=>true, "\u{00F6}"=>true];

    private bool $B_ending_removed = false;
    private int $I_p2 = 0;
    private int $I_p1 = 0;



    protected function r_mark_regions(): bool
    {
        $this->I_p1 = $this->limit;
        $this->I_p2 = $this->limit;
        if (!$this->go_out_grouping(self::G_V1)) {
            return false;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_V1)) {
            return false;
        }
        $this->inc_cursor();
        $this->I_p1 = $this->cursor;
        if (!$this->go_out_grouping(self::G_V1)) {
            return false;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_V1)) {
            return false;
        }
        $this->inc_cursor();
        $this->I_p2 = $this->cursor;
        return true;
    }


    protected function r_R2(): bool
    {
        return $this->I_p2 <= $this->cursor;
    }


    protected function r_particle_etc(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_0);
        if (0 === $among_var) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        switch ($among_var) {
            case 1:
                if (!($this->in_grouping_b(self::G_particle_end))) {
                    return false;
                }
                break;
            case 2:
                if (!$this->r_R2()) {
                    return false;
                }
                break;
        }
        $this->slice_del();
        return true;
    }


    protected function r_possessive(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_4);
        if (0 === $among_var) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        switch ($among_var) {
            case 1:
                $v_2 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("k"))) {
                    goto lab0;
                }
                return false;
            lab0:
                $this->cursor = $this->limit - $v_2;
                $this->slice_del();
                break;
            case 2:
                $this->slice_del();
                $this->ket = $this->cursor;
                if (!($this->eq_s_b("kse"))) {
                    return false;
                }
                $this->bra = $this->cursor;
                $this->slice_from("ksi");
                break;
            case 3:
                $this->slice_del();
                break;
            case 4:
                if ($this->find_among_b(self::A_1) === 0) {
                    return false;
                }
                $this->slice_del();
                break;
            case 5:
                if ($this->find_among_b(self::A_2) === 0) {
                    return false;
                }
                $this->slice_del();
                break;
            case 6:
                if ($this->find_among_b(self::A_3) === 0) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_LONG(): bool
    {
        return $this->find_among_b(self::A_5) !== 0;
    }


    protected function r_VI(): bool
    {
        if (!($this->eq_s_b("i"))) {
            return false;
        }
        return $this->in_grouping_b(self::G_V2);
    }


    protected function r_case_ending(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_6);
        if (0 === $among_var) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        switch ($among_var) {
            case 1:
                if (!($this->eq_s_b("a"))) {
                    return false;
                }
                break;
            case 2:
                if (!($this->eq_s_b("e"))) {
                    return false;
                }
                break;
            case 3:
                if (!($this->eq_s_b("i"))) {
                    return false;
                }
                break;
            case 4:
                if (!($this->eq_s_b("o"))) {
                    return false;
                }
                break;
            case 5:
                if (!($this->eq_s_b("\u{00E4}"))) {
                    return false;
                }
                break;
            case 6:
                if (!($this->eq_s_b("\u{00F6}"))) {
                    return false;
                }
                break;
            case 7:
                $v_2 = $this->limit - $this->cursor;
                $v_3 = $this->limit - $this->cursor;
                $v_4 = $this->limit - $this->cursor;
                if (!$this->r_LONG()) {
                    goto lab1;
                }
                goto lab2;
            lab1:
                $this->cursor = $this->limit - $v_4;
                if (!($this->eq_s_b("ie"))) {
                    $this->cursor = $this->limit - $v_2;
                    goto lab0;
                }
            lab2:
                $this->cursor = $this->limit - $v_3;
                if ($this->cursor <= $this->limit_backward) {
                    $this->cursor = $this->limit - $v_2;
                    goto lab0;
                }
                $this->dec_cursor();
                $this->bra = $this->cursor;
            lab0:
                break;
            case 8:
                if (!($this->in_grouping_b(self::G_V1))) {
                    return false;
                }
                if (!($this->in_grouping_b(self::G_C))) {
                    return false;
                }
                break;
        }
        $this->slice_del();
        $this->B_ending_removed = true;
        return true;
    }


    protected function r_other_endings(): bool
    {
        if ($this->cursor < $this->I_p2) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p2;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_7);
        if (0 === $among_var) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        switch ($among_var) {
            case 1:
                $v_2 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("po"))) {
                    goto lab0;
                }
                return false;
            lab0:
                $this->cursor = $this->limit - $v_2;
                break;
        }
        $this->slice_del();
        return true;
    }


    protected function r_i_plural(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_8) === 0) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        $this->slice_del();
        return true;
    }


    protected function r_t_plural(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("t"))) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $v_2 = $this->limit - $this->cursor;
        if (!($this->in_grouping_b(self::G_V1))) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->cursor = $this->limit - $v_2;
        $this->slice_del();
        $this->limit_backward = $v_1;
        if ($this->cursor < $this->I_p2) {
            return false;
        }
        $v_3 = $this->limit_backward;
        $this->limit_backward = $this->I_p2;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_9);
        if (0 === $among_var) {
            $this->limit_backward = $v_3;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_3;
        switch ($among_var) {
            case 1:
                $v_4 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("po"))) {
                    goto lab0;
                }
                return false;
            lab0:
                $this->cursor = $this->limit - $v_4;
                break;
        }
        $this->slice_del();
        return true;
    }


    protected function r_tidy(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $v_2 = $this->limit - $this->cursor;
        $v_3 = $this->limit - $this->cursor;
        if (!$this->r_LONG()) {
            goto lab0;
        }
        $this->cursor = $this->limit - $v_3;
        $this->ket = $this->cursor;
        if ($this->cursor <= $this->limit_backward) {
            goto lab0;
        }
        $this->dec_cursor();
        $this->bra = $this->cursor;
        $this->slice_del();
    lab0:
        $this->cursor = $this->limit - $v_2;
        $v_4 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!($this->in_grouping_b(self::G_AEI))) {
            goto lab1;
        }
        $this->bra = $this->cursor;
        if (!($this->in_grouping_b(self::G_C))) {
            goto lab1;
        }
        $this->slice_del();
    lab1:
        $this->cursor = $this->limit - $v_4;
        $v_5 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("j"))) {
            goto lab2;
        }
        $this->bra = $this->cursor;
        $v_6 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("o"))) {
            goto lab3;
        }
        goto lab4;
    lab3:
        $this->cursor = $this->limit - $v_6;
        if (!($this->eq_s_b("u"))) {
            goto lab2;
        }
    lab4:
        $this->slice_del();
    lab2:
        $this->cursor = $this->limit - $v_5;
        $v_7 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("o"))) {
            goto lab5;
        }
        $this->bra = $this->cursor;
        if (!($this->eq_s_b("j"))) {
            goto lab5;
        }
        $this->slice_del();
    lab5:
        $this->cursor = $this->limit - $v_7;
        $this->limit_backward = $v_1;
        if (!$this->go_in_grouping_b(self::G_V1)) {
            return false;
        }
        $this->ket = $this->cursor;
        if (!($this->in_grouping_b(self::G_C))) {
            return false;
        }
        $this->bra = $this->cursor;
        $S_x = $this->slice_to();
        if (!($this->eq_s_b($S_x))) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    public function stem(): bool
    {
        $v_1 = $this->cursor;
        $this->r_mark_regions();
        $this->cursor = $v_1;
        $this->B_ending_removed = false;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_2 = $this->limit - $this->cursor;
        $this->r_particle_etc();
        $this->cursor = $this->limit - $v_2;
        $v_3 = $this->limit - $this->cursor;
        $this->r_possessive();
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        $this->r_case_ending();
        $this->cursor = $this->limit - $v_4;
        $v_5 = $this->limit - $this->cursor;
        $this->r_other_endings();
        $this->cursor = $this->limit - $v_5;
        if (!$this->B_ending_removed) {
            goto lab0;
        }
        $v_6 = $this->limit - $this->cursor;
        $this->r_i_plural();
        $this->cursor = $this->limit - $v_6;
        goto lab1;
    lab0:
        $v_7 = $this->limit - $this->cursor;
        $this->r_t_plural();
        $this->cursor = $this->limit - $v_7;
    lab1:
        $v_8 = $this->limit - $this->cursor;
        $this->r_tidy();
        $this->cursor = $this->limit - $v_8;
        $this->cursor = $this->limit_backward;
        return true;
    }
}
