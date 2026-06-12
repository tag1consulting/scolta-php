<?php

namespace Tag1\Scolta\Index\Snowball;
// Generated from norwegian.sbl by Snowball 3.0.0 - https://snowballstem.org/

class NorwegianStemmer extends SnowballStemmer
{
    private const A_0 = [
        ["", -1, 1],
        ["ind", 0, -1],
        ["kk", 0, -1],
        ["nk", 0, -1],
        ["amm", 0, -1],
        ["omm", 0, -1],
        ["kap", 0, -1],
        ["skap", 6, 1],
        ["pp", 0, -1],
        ["lt", 0, -1],
        ["ast", 0, -1],
        ["\u{00F8}st", 0, -1],
        ["v", 0, -1],
        ["hav", 12, 1],
        ["giv", 12, 1]
    ];

    private const A_1 = [
        ["a", -1, 1],
        ["e", -1, 1],
        ["ede", 1, 1],
        ["ande", 1, 1],
        ["ende", 1, 1],
        ["ane", 1, 1],
        ["ene", 1, 1],
        ["hetene", 6, 1],
        ["erte", 1, 4],
        ["en", -1, 1],
        ["heten", 9, 1],
        ["ar", -1, 1],
        ["er", -1, 1],
        ["heter", 12, 1],
        ["s", -1, 3],
        ["as", 14, 1],
        ["es", 14, 1],
        ["edes", 16, 1],
        ["endes", 16, 1],
        ["enes", 16, 1],
        ["hetenes", 19, 1],
        ["ens", 14, 1],
        ["hetens", 21, 1],
        ["ers", 14, 2],
        ["ets", 14, 1],
        ["et", -1, 1],
        ["het", 25, 1],
        ["ert", -1, 4],
        ["ast", -1, 1]
    ];

    private const A_2 = [
        ["dt", -1, -1],
        ["vt", -1, -1]
    ];

    private const A_3 = [
        ["leg", -1, 1],
        ["eleg", 0, 1],
        ["ig", -1, 1],
        ["eig", 2, 1],
        ["lig", 2, 1],
        ["elig", 4, 1],
        ["els", -1, 1],
        ["lov", -1, 1],
        ["elov", 7, 1],
        ["slov", 7, 1],
        ["hetslov", 9, 1]
    ];

    private const G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "y"=>true, "\u{00E5}"=>true, "\u{00E6}"=>true, "\u{00EA}"=>true, "\u{00F2}"=>true, "\u{00F3}"=>true, "\u{00F4}"=>true, "\u{00F8}"=>true];

    private const G_s_ending = ["b"=>true, "c"=>true, "d"=>true, "f"=>true, "g"=>true, "h"=>true, "j"=>true, "l"=>true, "m"=>true, "n"=>true, "o"=>true, "p"=>true, "t"=>true, "v"=>true, "y"=>true, "z"=>true];

    private int $I_p1 = 0;



    protected function r_mark_regions(): bool
    {
        $this->I_p1 = $this->limit;
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
        return true;
    }


    protected function r_main_suffix(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_1);
        if (0 === $among_var) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $among_var = $this->find_among_b(self::A_0);
                switch ($among_var) {
                    case 1:
                        $this->slice_del();
                        break;
                }
                break;
            case 3:
                $v_2 = $this->limit - $this->cursor;
                if (!($this->in_grouping_b(self::G_s_ending))) {
                    goto lab0;
                }
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_2;
                if (!($this->eq_s_b("r"))) {
                    goto lab2;
                }
                $v_3 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("e"))) {
                    goto lab3;
                }
                goto lab2;
            lab3:
                $this->cursor = $this->limit - $v_3;
                goto lab1;
            lab2:
                $this->cursor = $this->limit - $v_2;
                if (!($this->eq_s_b("k"))) {
                    return false;
                }
                if (!($this->out_grouping_b(self::G_v))) {
                    return false;
                }
            lab1:
                $this->slice_del();
                break;
            case 4:
                $this->slice_from("er");
                break;
        }
        return true;
    }


    protected function r_consonant_pair(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_2 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_2) === 0) {
            $this->limit_backward = $v_2;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_2;
        $this->cursor = $this->limit - $v_1;
        if ($this->cursor <= $this->limit_backward) {
            return false;
        }
        $this->dec_cursor();
        $this->bra = $this->cursor;
        $this->slice_del();
        return true;
    }


    protected function r_other_suffix(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_3) === 0) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        $this->slice_del();
        return true;
    }


    public function stem(): bool
    {
        $v_1 = $this->cursor;
        $this->r_mark_regions();
        $this->cursor = $v_1;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_2 = $this->limit - $this->cursor;
        $this->r_main_suffix();
        $this->cursor = $this->limit - $v_2;
        $v_3 = $this->limit - $this->cursor;
        $this->r_consonant_pair();
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        $this->r_other_suffix();
        $this->cursor = $this->limit - $v_4;
        $this->cursor = $this->limit_backward;
        return true;
    }
}
