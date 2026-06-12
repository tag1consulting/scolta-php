<?php

namespace Tag1\Scolta\Index\Snowball;
// Generated from danish.sbl by Snowball 3.0.0 - https://snowballstem.org/

class DanishStemmer extends SnowballStemmer
{
    private const A_0 = [
        ["hed", -1, 1],
        ["ethed", 0, 1],
        ["ered", -1, 1],
        ["e", -1, 1],
        ["erede", 3, 1],
        ["ende", 3, 1],
        ["erende", 5, 1],
        ["ene", 3, 1],
        ["erne", 3, 1],
        ["ere", 3, 1],
        ["en", -1, 1],
        ["heden", 10, 1],
        ["eren", 10, 1],
        ["er", -1, 1],
        ["heder", 13, 1],
        ["erer", 13, 1],
        ["s", -1, 2],
        ["heds", 16, 1],
        ["es", 16, 1],
        ["endes", 18, 1],
        ["erendes", 19, 1],
        ["enes", 18, 1],
        ["ernes", 18, 1],
        ["eres", 18, 1],
        ["ens", 16, 1],
        ["hedens", 24, 1],
        ["erens", 24, 1],
        ["ers", 16, 1],
        ["ets", 16, 1],
        ["erets", 28, 1],
        ["et", -1, 1],
        ["eret", 30, 1]
    ];

    private const A_1 = [
        ["gd", -1, -1],
        ["dt", -1, -1],
        ["gt", -1, -1],
        ["kt", -1, -1]
    ];

    private const A_2 = [
        ["ig", -1, 1],
        ["lig", 0, 1],
        ["elig", 1, 1],
        ["els", -1, 1],
        ["l\u{00F8}st", -1, 2]
    ];

    private const G_c = ["b"=>true, "c"=>true, "d"=>true, "f"=>true, "g"=>true, "h"=>true, "j"=>true, "k"=>true, "l"=>true, "m"=>true, "n"=>true, "p"=>true, "q"=>true, "r"=>true, "s"=>true, "t"=>true, "v"=>true, "w"=>true, "x"=>true, "z"=>true];

    private const G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "y"=>true, "\u{00E5}"=>true, "\u{00E6}"=>true, "\u{00F8}"=>true];

    private const G_s_ending = ["a"=>true, "b"=>true, "c"=>true, "d"=>true, "f"=>true, "g"=>true, "h"=>true, "j"=>true, "k"=>true, "l"=>true, "m"=>true, "n"=>true, "o"=>true, "p"=>true, "r"=>true, "t"=>true, "v"=>true, "y"=>true, "z"=>true, "\u{00E5}"=>true];

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
        $among_var = $this->find_among_b(self::A_0);
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
                if (!($this->in_grouping_b(self::G_s_ending))) {
                    return false;
                }
                $this->slice_del();
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
        if ($this->find_among_b(self::A_1) === 0) {
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
        $v_1 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("st"))) {
            goto lab0;
        }
        $this->bra = $this->cursor;
        if (!($this->eq_s_b("ig"))) {
            goto lab0;
        }
        $this->slice_del();
    lab0:
        $this->cursor = $this->limit - $v_1;
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_2 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_2);
        if (0 === $among_var) {
            $this->limit_backward = $v_2;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_2;
        switch ($among_var) {
            case 1:
                $this->slice_del();
                $v_3 = $this->limit - $this->cursor;
                $this->r_consonant_pair();
                $this->cursor = $this->limit - $v_3;
                break;
            case 2:
                $this->slice_from("l\u{00F8}s");
                break;
        }
        return true;
    }


    protected function r_undouble(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        if (!($this->in_grouping_b(self::G_c))) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $S_ch = $this->slice_to();
        $this->limit_backward = $v_1;
        if (!($this->eq_s_b($S_ch))) {
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
        $v_5 = $this->limit - $this->cursor;
        $this->r_undouble();
        $this->cursor = $this->limit - $v_5;
        $this->cursor = $this->limit_backward;
        return true;
    }
}
