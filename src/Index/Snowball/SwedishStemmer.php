<?php

namespace Tag1\Scolta\Index\Snowball;
// Generated from swedish.sbl by Snowball 3.0.0 - https://snowballstem.org/

class SwedishStemmer extends SnowballStemmer
{
    private const A_0 = [
        ["fab", -1, -1],
        ["h", -1, -1],
        ["pak", -1, -1],
        ["rak", -1, -1],
        ["stak", -1, -1],
        ["kom", -1, -1],
        ["iet", -1, -1],
        ["cit", -1, -1],
        ["dit", -1, -1],
        ["alit", -1, -1],
        ["ilit", -1, -1],
        ["mit", -1, -1],
        ["nit", -1, -1],
        ["pit", -1, -1],
        ["rit", -1, -1],
        ["sit", -1, -1],
        ["tit", -1, -1],
        ["uit", -1, -1],
        ["ivit", -1, -1],
        ["kvit", -1, -1],
        ["xit", -1, -1]
    ];

    private const A_1 = [
        ["a", -1, 1],
        ["arna", 0, 1],
        ["erna", 0, 1],
        ["heterna", 2, 1],
        ["orna", 0, 1],
        ["ad", -1, 1],
        ["e", -1, 1],
        ["ade", 6, 1],
        ["ande", 6, 1],
        ["arne", 6, 1],
        ["are", 6, 1],
        ["aste", 6, 1],
        ["en", -1, 1],
        ["anden", 12, 1],
        ["aren", 12, 1],
        ["heten", 12, 1],
        ["ern", -1, 1],
        ["ar", -1, 1],
        ["er", -1, 1],
        ["heter", 18, 1],
        ["or", -1, 1],
        ["s", -1, 2],
        ["as", 21, 1],
        ["arnas", 22, 1],
        ["ernas", 22, 1],
        ["ornas", 22, 1],
        ["es", 21, 1],
        ["ades", 26, 1],
        ["andes", 26, 1],
        ["ens", 21, 1],
        ["arens", 29, 1],
        ["hetens", 29, 1],
        ["erns", 21, 1],
        ["at", -1, 1],
        ["et", -1, 3],
        ["andet", 34, 1],
        ["het", 34, 1],
        ["ast", -1, 1]
    ];

    private const A_2 = [
        ["dd", -1, -1],
        ["gd", -1, -1],
        ["nn", -1, -1],
        ["dt", -1, -1],
        ["gt", -1, -1],
        ["kt", -1, -1],
        ["tt", -1, -1]
    ];

    private const A_3 = [
        ["ig", -1, 1],
        ["lig", 0, 1],
        ["els", -1, 1],
        ["fullt", -1, 3],
        ["\u{00F6}st", -1, 2]
    ];

    private const G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "y"=>true, "\u{00E4}"=>true, "\u{00E5}"=>true, "\u{00F6}"=>true];

    private const G_s_ending = ["b"=>true, "c"=>true, "d"=>true, "f"=>true, "g"=>true, "h"=>true, "j"=>true, "k"=>true, "l"=>true, "m"=>true, "n"=>true, "o"=>true, "p"=>true, "r"=>true, "t"=>true, "v"=>true, "y"=>true];

    private const G_ost_ending = ["i"=>true, "k"=>true, "l"=>true, "n"=>true, "p"=>true, "r"=>true, "t"=>true, "u"=>true, "v"=>true];

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


    protected function r_et_condition(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        if (!($this->out_grouping_b(self::G_v))) {
            return false;
        }
        if (!($this->in_grouping_b(self::G_v))) {
            return false;
        }
        if ($this->cursor > $this->limit_backward) {
            goto lab0;
        }
        return false;
    lab0:
        $this->cursor = $this->limit - $v_1;
        $v_2 = $this->limit - $this->cursor;
        if ($this->find_among_b(self::A_0) === 0) {
            goto lab1;
        }
        return false;
    lab1:
        $this->cursor = $this->limit - $v_2;
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
                $v_2 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("et"))) {
                    goto lab0;
                }
                if (!$this->r_et_condition()) {
                    goto lab0;
                }
                $this->bra = $this->cursor;
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_2;
                if (!($this->in_grouping_b(self::G_s_ending))) {
                    return false;
                }
            lab1:
                $this->slice_del();
                break;
            case 3:
                if (!$this->r_et_condition()) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_consonant_pair(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $v_2 = $this->limit - $this->cursor;
        if ($this->find_among_b(self::A_2) === 0) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->cursor = $this->limit - $v_2;
        $this->ket = $this->cursor;
        if ($this->cursor <= $this->limit_backward) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->dec_cursor();
        $this->bra = $this->cursor;
        $this->slice_del();
        $this->limit_backward = $v_1;
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
        $among_var = $this->find_among_b(self::A_3);
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
                if (!($this->in_grouping_b(self::G_ost_ending))) {
                    return false;
                }
                $this->slice_from("\u{00F6}s");
                break;
            case 3:
                $this->slice_from("full");
                break;
        }
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
