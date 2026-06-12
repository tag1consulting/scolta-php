<?php

namespace Tag1\Scolta\Index\Snowball;
// Generated from dutch.sbl by Snowball 3.0.0 - https://snowballstem.org/

class DutchStemmer extends SnowballStemmer
{
    private const A_0 = [
        ["a", -1, 1],
        ["e", -1, 2],
        ["o", -1, 1],
        ["u", -1, 1],
        ["\u{00E0}", -1, 1],
        ["\u{00E1}", -1, 1],
        ["\u{00E2}", -1, 1],
        ["\u{00E4}", -1, 1],
        ["\u{00E8}", -1, 2],
        ["\u{00E9}", -1, 2],
        ["\u{00EA}", -1, 2],
        ["e\u{00EB}", -1, 3],
        ["i\u{00EB}", -1, 4],
        ["\u{00F2}", -1, 1],
        ["\u{00F3}", -1, 1],
        ["\u{00F4}", -1, 1],
        ["\u{00F6}", -1, 1],
        ["\u{00F9}", -1, 1],
        ["\u{00FA}", -1, 1],
        ["\u{00FB}", -1, 1],
        ["\u{00FC}", -1, 1]
    ];

    private const A_1 = [
        ["nde", -1, 8],
        ["en", -1, 7],
        ["s", -1, 2],
        ["'s", 2, 1],
        ["es", 2, 4],
        ["ies", 4, 3],
        ["aus", 2, 6],
        ["\u{00E9}s", 2, 5]
    ];

    private const A_2 = [
        ["de", -1, 5],
        ["ge", -1, 2],
        ["ische", -1, 4],
        ["je", -1, 1],
        ["lijke", -1, 3],
        ["le", -1, 9],
        ["ene", -1, 10],
        ["re", -1, 8],
        ["se", -1, 7],
        ["te", -1, 6],
        ["ieve", -1, 11]
    ];

    private const A_3 = [
        ["heid", -1, 3],
        ["fie", -1, 7],
        ["gie", -1, 8],
        ["atie", -1, 1],
        ["isme", -1, 5],
        ["ing", -1, 5],
        ["arij", -1, 6],
        ["erij", -1, 5],
        ["sel", -1, 3],
        ["rder", -1, 4],
        ["ster", -1, 3],
        ["iteit", -1, 2],
        ["dst", -1, 10],
        ["tst", -1, 9]
    ];

    private const A_4 = [
        ["end", -1, 9],
        ["atief", -1, 2],
        ["erig", -1, 9],
        ["achtig", -1, 3],
        ["ioneel", -1, 1],
        ["baar", -1, 3],
        ["laar", -1, 5],
        ["naar", -1, 4],
        ["raar", -1, 6],
        ["eriger", -1, 9],
        ["achtiger", -1, 3],
        ["lijker", -1, 8],
        ["tant", -1, 7],
        ["erigst", -1, 9],
        ["achtigst", -1, 3],
        ["lijkst", -1, 8]
    ];

    private const A_5 = [
        ["ig", -1, 1],
        ["iger", -1, 1],
        ["igst", -1, 1]
    ];

    private const A_6 = [
        ["ft", -1, 2],
        ["kt", -1, 1],
        ["pt", -1, 3]
    ];

    private const A_7 = [
        ["bb", -1, 1],
        ["cc", -1, 2],
        ["dd", -1, 3],
        ["ff", -1, 4],
        ["gg", -1, 5],
        ["hh", -1, 6],
        ["jj", -1, 7],
        ["kk", -1, 8],
        ["ll", -1, 9],
        ["mm", -1, 10],
        ["nn", -1, 11],
        ["pp", -1, 12],
        ["qq", -1, 13],
        ["rr", -1, 14],
        ["ss", -1, 15],
        ["tt", -1, 16],
        ["v", -1, 4],
        ["vv", 16, 17],
        ["ww", -1, 18],
        ["xx", -1, 19],
        ["z", -1, 15],
        ["zz", 20, 20]
    ];

    private const A_8 = [
        ["d", -1, 1],
        ["t", -1, 2]
    ];

    private const A_9 = [
        ["", -1, -1],
        ["eft", 0, 1],
        ["vaa", 0, 1],
        ["val", 0, 1],
        ["vali", 3, -1],
        ["vare", 0, 1]
    ];

    private const A_10 = [
        ["\u{00EB}", -1, 1],
        ["\u{00EF}", -1, 2]
    ];

    private const A_11 = [
        ["\u{00EB}", -1, 1],
        ["\u{00EF}", -1, 2]
    ];

    private const G_E = ["e"=>true, "\u{00E8}"=>true, "\u{00E9}"=>true, "\u{00EA}"=>true, "\u{00EB}"=>true];

    private const G_AIOU = ["a"=>true, "i"=>true, "o"=>true, "u"=>true, "\u{00E0}"=>true, "\u{00E1}"=>true, "\u{00E2}"=>true, "\u{00E4}"=>true, "\u{00EC}"=>true, "\u{00ED}"=>true, "\u{00EE}"=>true, "\u{00EF}"=>true, "\u{00F2}"=>true, "\u{00F3}"=>true, "\u{00F4}"=>true, "\u{00F6}"=>true, "\u{00F9}"=>true, "\u{00FA}"=>true, "\u{00FB}"=>true, "\u{00FC}"=>true];

    private const G_AEIOU = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "\u{00E0}"=>true, "\u{00E1}"=>true, "\u{00E2}"=>true, "\u{00E4}"=>true, "\u{00E8}"=>true, "\u{00E9}"=>true, "\u{00EA}"=>true, "\u{00EB}"=>true, "\u{00EC}"=>true, "\u{00ED}"=>true, "\u{00EE}"=>true, "\u{00EF}"=>true, "\u{00F2}"=>true, "\u{00F3}"=>true, "\u{00F4}"=>true, "\u{00F6}"=>true, "\u{00F9}"=>true, "\u{00FA}"=>true, "\u{00FB}"=>true, "\u{00FC}"=>true];

    private const G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "y"=>true, "\u{00E0}"=>true, "\u{00E1}"=>true, "\u{00E2}"=>true, "\u{00E4}"=>true, "\u{00E8}"=>true, "\u{00E9}"=>true, "\u{00EA}"=>true, "\u{00EB}"=>true, "\u{00EC}"=>true, "\u{00ED}"=>true, "\u{00EE}"=>true, "\u{00EF}"=>true, "\u{00F2}"=>true, "\u{00F3}"=>true, "\u{00F4}"=>true, "\u{00F6}"=>true, "\u{00F9}"=>true, "\u{00FA}"=>true, "\u{00FB}"=>true, "\u{00FC}"=>true];

    private const G_v_WX = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "w"=>true, "x"=>true, "y"=>true, "\u{00E0}"=>true, "\u{00E1}"=>true, "\u{00E2}"=>true, "\u{00E4}"=>true, "\u{00E8}"=>true, "\u{00E9}"=>true, "\u{00EA}"=>true, "\u{00EB}"=>true, "\u{00EC}"=>true, "\u{00ED}"=>true, "\u{00EE}"=>true, "\u{00EF}"=>true, "\u{00F2}"=>true, "\u{00F3}"=>true, "\u{00F4}"=>true, "\u{00F6}"=>true, "\u{00F9}"=>true, "\u{00FA}"=>true, "\u{00FB}"=>true, "\u{00FC}"=>true];

    private bool $B_GE_removed = false;
    private int $I_p2 = 0;
    private int $I_p1 = 0;



    protected function r_R1(): bool
    {
        return $this->I_p1 <= $this->cursor;
    }


    protected function r_R2(): bool
    {
        return $this->I_p2 <= $this->cursor;
    }


    protected function r_V(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        $v_2 = $this->limit - $this->cursor;
        if (!($this->in_grouping_b(self::G_v))) {
            goto lab0;
        }
        goto lab1;
    lab0:
        $this->cursor = $this->limit - $v_2;
        if (!($this->eq_s_b("ij"))) {
            return false;
        }
    lab1:
        $this->cursor = $this->limit - $v_1;
        return true;
    }


    protected function r_VX(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        if ($this->cursor <= $this->limit_backward) {
            return false;
        }
        $this->dec_cursor();
        $v_2 = $this->limit - $this->cursor;
        if (!($this->in_grouping_b(self::G_v))) {
            goto lab0;
        }
        goto lab1;
    lab0:
        $this->cursor = $this->limit - $v_2;
        if (!($this->eq_s_b("ij"))) {
            return false;
        }
    lab1:
        $this->cursor = $this->limit - $v_1;
        return true;
    }


    protected function r_C(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        $v_2 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("ij"))) {
            goto lab0;
        }
        return false;
    lab0:
        $this->cursor = $this->limit - $v_2;
        if (!($this->out_grouping_b(self::G_v))) {
            return false;
        }
        $this->cursor = $this->limit - $v_1;
        return true;
    }


    protected function r_lengthen_V(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        if (!($this->out_grouping_b(self::G_v_WX))) {
            goto lab0;
        }
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_0);
        if (0 === $among_var) {
            goto lab0;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $v_2 = $this->limit - $this->cursor;
                $v_3 = $this->limit - $this->cursor;
                if (!($this->out_grouping_b(self::G_AEIOU))) {
                    goto lab1;
                }
                goto lab2;
            lab1:
                $this->cursor = $this->limit - $v_3;
                if ($this->cursor > $this->limit_backward) {
                    goto lab0;
                }
            lab2:
                $this->cursor = $this->limit - $v_2;
                $S_ch = $this->slice_to();
                $c = $this->cursor;
                $this->insert($c, $c, $S_ch);
                $this->cursor = $c;
                break;
            case 2:
                $v_4 = $this->limit - $this->cursor;
                $v_5 = $this->limit - $this->cursor;
                if (!($this->out_grouping_b(self::G_AEIOU))) {
                    goto lab3;
                }
                goto lab4;
            lab3:
                $this->cursor = $this->limit - $v_5;
                if ($this->cursor > $this->limit_backward) {
                    goto lab0;
                }
            lab4:
                $v_6 = $this->limit - $this->cursor;
                $v_7 = $this->limit - $this->cursor;
                if (!($this->in_grouping_b(self::G_AIOU))) {
                    goto lab6;
                }
                goto lab7;
            lab6:
                $this->cursor = $this->limit - $v_7;
                if (!($this->in_grouping_b(self::G_E))) {
                    goto lab5;
                }
                if ($this->cursor > $this->limit_backward) {
                    goto lab5;
                }
            lab7:
                goto lab0;
            lab5:
                $this->cursor = $this->limit - $v_6;
                $v_8 = $this->limit - $this->cursor;
                if ($this->cursor <= $this->limit_backward) {
                    goto lab8;
                }
                $this->dec_cursor();
                if (!($this->in_grouping_b(self::G_AIOU))) {
                    goto lab8;
                }
                if (!($this->out_grouping_b(self::G_AEIOU))) {
                    goto lab8;
                }
                goto lab0;
            lab8:
                $this->cursor = $this->limit - $v_8;
                $this->cursor = $this->limit - $v_4;
                $S_ch = $this->slice_to();
                $c = $this->cursor;
                $this->insert($c, $c, $S_ch);
                $this->cursor = $c;
                break;
            case 3:
                $this->slice_from("e\u{00EB}e");
                break;
            case 4:
                $this->slice_from("iee");
                break;
        }
    lab0:
        $this->cursor = $this->limit - $v_1;
        return true;
    }


    protected function r_Step_1(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_1);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                if (!$this->r_R1()) {
                    return false;
                }
                $v_1 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("t"))) {
                    goto lab0;
                }
                if (!$this->r_R1()) {
                    goto lab0;
                }
                return false;
            lab0:
                $this->cursor = $this->limit - $v_1;
                if (!$this->r_C()) {
                    return false;
                }
                $this->slice_del();
                break;
            case 3:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_from("ie");
                break;
            case 4:
                $v_2 = $this->limit - $this->cursor;
                $v_3 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("ar"))) {
                    goto lab1;
                }
                if (!$this->r_R1()) {
                    goto lab1;
                }
                if (!$this->r_C()) {
                    goto lab1;
                }
                $this->cursor = $this->limit - $v_3;
                $this->slice_del();
                $this->r_lengthen_V();
                goto lab2;
            lab1:
                $this->cursor = $this->limit - $v_2;
                $v_4 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("er"))) {
                    goto lab3;
                }
                if (!$this->r_R1()) {
                    goto lab3;
                }
                if (!$this->r_C()) {
                    goto lab3;
                }
                $this->cursor = $this->limit - $v_4;
                $this->slice_del();
                goto lab2;
            lab3:
                $this->cursor = $this->limit - $v_2;
                if (!$this->r_R1()) {
                    return false;
                }
                if (!$this->r_C()) {
                    return false;
                }
                $this->slice_from("e");
            lab2:
                break;
            case 5:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_from("\u{00E9}");
                break;
            case 6:
                if (!$this->r_R1()) {
                    return false;
                }
                if (!$this->r_V()) {
                    return false;
                }
                $this->slice_from("au");
                break;
            case 7:
                $v_5 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("hed"))) {
                    goto lab4;
                }
                if (!$this->r_R1()) {
                    goto lab4;
                }
                $this->bra = $this->cursor;
                $this->slice_from("heid");
                goto lab5;
            lab4:
                $this->cursor = $this->limit - $v_5;
                if (!($this->eq_s_b("nd"))) {
                    goto lab6;
                }
                $this->slice_del();
                goto lab5;
            lab6:
                $this->cursor = $this->limit - $v_5;
                if (!($this->eq_s_b("d"))) {
                    goto lab7;
                }
                if (!$this->r_R1()) {
                    goto lab7;
                }
                if (!$this->r_C()) {
                    goto lab7;
                }
                $this->bra = $this->cursor;
                $this->slice_del();
                goto lab5;
            lab7:
                $this->cursor = $this->limit - $v_5;
                $v_6 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("i"))) {
                    goto lab9;
                }
                goto lab10;
            lab9:
                $this->cursor = $this->limit - $v_6;
                if (!($this->eq_s_b("j"))) {
                    goto lab8;
                }
            lab10:
                if (!$this->r_V()) {
                    goto lab8;
                }
                $this->slice_del();
                goto lab5;
            lab8:
                $this->cursor = $this->limit - $v_5;
                if (!$this->r_R1()) {
                    return false;
                }
                if (!$this->r_C()) {
                    return false;
                }
                $this->slice_del();
                $this->r_lengthen_V();
            lab5:
                break;
            case 8:
                $this->slice_from("nd");
                break;
        }
        return true;
    }


    protected function r_Step_2(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_2);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $v_1 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("'t"))) {
                    goto lab0;
                }
                $this->bra = $this->cursor;
                $this->slice_del();
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_1;
                if (!($this->eq_s_b("et"))) {
                    goto lab2;
                }
                $this->bra = $this->cursor;
                if (!$this->r_R1()) {
                    goto lab2;
                }
                if (!$this->r_C()) {
                    goto lab2;
                }
                $this->slice_del();
                goto lab1;
            lab2:
                $this->cursor = $this->limit - $v_1;
                if (!($this->eq_s_b("rnt"))) {
                    goto lab3;
                }
                $this->bra = $this->cursor;
                $this->slice_from("rn");
                goto lab1;
            lab3:
                $this->cursor = $this->limit - $v_1;
                if (!($this->eq_s_b("t"))) {
                    goto lab4;
                }
                $this->bra = $this->cursor;
                if (!$this->r_R1()) {
                    goto lab4;
                }
                if (!$this->r_VX()) {
                    goto lab4;
                }
                $this->slice_del();
                goto lab1;
            lab4:
                $this->cursor = $this->limit - $v_1;
                if (!($this->eq_s_b("ink"))) {
                    goto lab5;
                }
                $this->bra = $this->cursor;
                $this->slice_from("ing");
                goto lab1;
            lab5:
                $this->cursor = $this->limit - $v_1;
                if (!($this->eq_s_b("mp"))) {
                    goto lab6;
                }
                $this->bra = $this->cursor;
                $this->slice_from("m");
                goto lab1;
            lab6:
                $this->cursor = $this->limit - $v_1;
                if (!($this->eq_s_b("'"))) {
                    goto lab7;
                }
                $this->bra = $this->cursor;
                if (!$this->r_R1()) {
                    goto lab7;
                }
                $this->slice_del();
                goto lab1;
            lab7:
                $this->cursor = $this->limit - $v_1;
                $this->bra = $this->cursor;
                if (!$this->r_R1()) {
                    return false;
                }
                if (!$this->r_C()) {
                    return false;
                }
                $this->slice_del();
            lab1:
                break;
            case 2:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_from("g");
                break;
            case 3:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_from("lijk");
                break;
            case 4:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_from("isch");
                break;
            case 5:
                if (!$this->r_R1()) {
                    return false;
                }
                if (!$this->r_C()) {
                    return false;
                }
                $this->slice_del();
                break;
            case 6:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_from("t");
                break;
            case 7:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_from("s");
                break;
            case 8:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_from("r");
                break;
            case 9:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_del();
                $this->insert($this->cursor, $this->cursor, "l");
                $this->r_lengthen_V();
                break;
            case 10:
                if (!$this->r_R1()) {
                    return false;
                }
                if (!$this->r_C()) {
                    return false;
                }
                $this->slice_del();
                $this->insert($this->cursor, $this->cursor, "en");
                $this->r_lengthen_V();
                break;
            case 11:
                if (!$this->r_R1()) {
                    return false;
                }
                if (!$this->r_C()) {
                    return false;
                }
                $this->slice_from("ief");
                break;
        }
        return true;
    }


    protected function r_Step_3(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_3);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_from("eer");
                break;
            case 2:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_del();
                $this->r_lengthen_V();
                break;
            case 3:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_del();
                break;
            case 4:
                $this->slice_from("r");
                break;
            case 5:
                $v_1 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("ild"))) {
                    goto lab0;
                }
                $this->slice_from("er");
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_1;
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_del();
                $this->r_lengthen_V();
            lab1:
                break;
            case 6:
                if (!$this->r_R1()) {
                    return false;
                }
                if (!$this->r_C()) {
                    return false;
                }
                $this->slice_from("aar");
                break;
            case 7:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                $this->insert($this->cursor, $this->cursor, "f");
                $this->r_lengthen_V();
                break;
            case 8:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                $this->insert($this->cursor, $this->cursor, "g");
                $this->r_lengthen_V();
                break;
            case 9:
                if (!$this->r_R1()) {
                    return false;
                }
                if (!$this->r_C()) {
                    return false;
                }
                $this->slice_from("t");
                break;
            case 10:
                if (!$this->r_R1()) {
                    return false;
                }
                if (!$this->r_C()) {
                    return false;
                }
                $this->slice_from("d");
                break;
        }
        return true;
    }


    protected function r_Step_4(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_4);
        if (0 === $among_var) {
            goto lab0;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_R1()) {
                    goto lab0;
                }
                $this->slice_from("ie");
                break;
            case 2:
                if (!$this->r_R1()) {
                    goto lab0;
                }
                $this->slice_from("eer");
                break;
            case 3:
                if (!$this->r_R1()) {
                    goto lab0;
                }
                $this->slice_del();
                break;
            case 4:
                if (!$this->r_R1()) {
                    goto lab0;
                }
                if (!$this->r_V()) {
                    goto lab0;
                }
                $this->slice_from("n");
                break;
            case 5:
                if (!$this->r_R1()) {
                    goto lab0;
                }
                if (!$this->r_V()) {
                    goto lab0;
                }
                $this->slice_from("l");
                break;
            case 6:
                if (!$this->r_R1()) {
                    goto lab0;
                }
                if (!$this->r_V()) {
                    goto lab0;
                }
                $this->slice_from("r");
                break;
            case 7:
                if (!$this->r_R1()) {
                    goto lab0;
                }
                $this->slice_from("teer");
                break;
            case 8:
                if (!$this->r_R1()) {
                    goto lab0;
                }
                $this->slice_from("lijk");
                break;
            case 9:
                if (!$this->r_R1()) {
                    goto lab0;
                }
                if (!$this->r_C()) {
                    goto lab0;
                }
                $this->slice_del();
                $this->r_lengthen_V();
                break;
        }
        goto lab1;
    lab0:
        $this->cursor = $this->limit - $v_1;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_5) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        $v_2 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("inn"))) {
            goto lab2;
        }
        if ($this->cursor > $this->limit_backward) {
            goto lab2;
        }
        return false;
    lab2:
        $this->cursor = $this->limit - $v_2;
        if (!$this->r_C()) {
            return false;
        }
        $this->slice_del();
        $this->r_lengthen_V();
    lab1:
        return true;
    }


    protected function r_Step_7(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_6);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_from("k");
                break;
            case 2:
                $this->slice_from("f");
                break;
            case 3:
                $this->slice_from("p");
                break;
        }
        return true;
    }


    protected function r_Step_6(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_7);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_from("b");
                break;
            case 2:
                $this->slice_from("c");
                break;
            case 3:
                $this->slice_from("d");
                break;
            case 4:
                $this->slice_from("f");
                break;
            case 5:
                $this->slice_from("g");
                break;
            case 6:
                $this->slice_from("h");
                break;
            case 7:
                $this->slice_from("j");
                break;
            case 8:
                $this->slice_from("k");
                break;
            case 9:
                $this->slice_from("l");
                break;
            case 10:
                $this->slice_from("m");
                break;
            case 11:
                $v_1 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("i"))) {
                    goto lab0;
                }
                if ($this->cursor > $this->limit_backward) {
                    goto lab0;
                }
                return false;
            lab0:
                $this->cursor = $this->limit - $v_1;
                $this->slice_from("n");
                break;
            case 12:
                $this->slice_from("p");
                break;
            case 13:
                $this->slice_from("q");
                break;
            case 14:
                $this->slice_from("r");
                break;
            case 15:
                $this->slice_from("s");
                break;
            case 16:
                $this->slice_from("t");
                break;
            case 17:
                $this->slice_from("v");
                break;
            case 18:
                $this->slice_from("w");
                break;
            case 19:
                $this->slice_from("x");
                break;
            case 20:
                $this->slice_from("z");
                break;
        }
        return true;
    }


    protected function r_Step_1c(): bool
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
        if (!$this->r_C()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $v_1 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("n"))) {
                    goto lab0;
                }
                if (!$this->r_R1()) {
                    goto lab0;
                }
                return false;
            lab0:
                $this->cursor = $this->limit - $v_1;
                $v_2 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("in"))) {
                    goto lab1;
                }
                if ($this->cursor > $this->limit_backward) {
                    goto lab1;
                }
                $this->slice_from("n");
                goto lab2;
            lab1:
                $this->cursor = $this->limit - $v_2;
                $this->slice_del();
            lab2:
                break;
            case 2:
                $v_3 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("h"))) {
                    goto lab3;
                }
                if (!$this->r_R1()) {
                    goto lab3;
                }
                return false;
            lab3:
                $this->cursor = $this->limit - $v_3;
                $v_4 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("en"))) {
                    goto lab4;
                }
                if ($this->cursor > $this->limit_backward) {
                    goto lab4;
                }
                return false;
            lab4:
                $this->cursor = $this->limit - $v_4;
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Lose_prefix(): bool
    {
        $this->bra = $this->cursor;
        if (!($this->eq_s("ge"))) {
            return false;
        }
        $this->ket = $this->cursor;
        $v_1 = $this->cursor;
        if (!$this->hop(3)) {
            return false;
        }
        $this->cursor = $v_1;
        $v_2 = $this->cursor;
        while (true) {
            $v_3 = $this->cursor;
            $v_4 = $this->cursor;
            if (!($this->eq_s("ij"))) {
                goto lab1;
            }
            goto lab2;
        lab1:
            $this->cursor = $v_4;
            if (!($this->in_grouping(self::G_v))) {
                goto lab0;
            }
        lab2:
            break;
        lab0:
            $this->cursor = $v_3;
            if ($this->cursor >= $this->limit) {
                return false;
            }
            $this->inc_cursor();
        }
        while (true) {
            $v_5 = $this->cursor;
            $v_6 = $this->cursor;
            if (!($this->eq_s("ij"))) {
                goto lab4;
            }
            goto lab5;
        lab4:
            $this->cursor = $v_6;
            if (!($this->in_grouping(self::G_v))) {
                goto lab3;
            }
        lab5:
            continue;
        lab3:
            $this->cursor = $v_5;
            break;
        }
        if ($this->cursor < $this->limit) {
            goto lab6;
        }
        return false;
    lab6:
        $this->cursor = $v_2;
        $among_var = $this->find_among(self::A_9);
        switch ($among_var) {
            case 1:
                return false;
        }
        $this->B_GE_removed = true;
        $this->slice_del();
        $v_7 = $this->cursor;
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_10);
        if (0 === $among_var) {
            goto lab7;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_from("e");
                break;
            case 2:
                $this->slice_from("i");
                break;
        }
    lab7:
        $this->cursor = $v_7;
        return true;
    }


    protected function r_Lose_infix(): bool
    {
        if ($this->cursor >= $this->limit) {
            return false;
        }
        $this->inc_cursor();
        while (true) {
            $this->bra = $this->cursor;
            if (!($this->eq_s("ge"))) {
                goto lab0;
            }
            $this->ket = $this->cursor;
            break;
        lab0:
            if ($this->cursor >= $this->limit) {
                return false;
            }
            $this->inc_cursor();
        }
        $v_1 = $this->cursor;
        if (!$this->hop(3)) {
            return false;
        }
        $this->cursor = $v_1;
        $v_2 = $this->cursor;
        while (true) {
            $v_3 = $this->cursor;
            $v_4 = $this->cursor;
            if (!($this->eq_s("ij"))) {
                goto lab2;
            }
            goto lab3;
        lab2:
            $this->cursor = $v_4;
            if (!($this->in_grouping(self::G_v))) {
                goto lab1;
            }
        lab3:
            break;
        lab1:
            $this->cursor = $v_3;
            if ($this->cursor >= $this->limit) {
                return false;
            }
            $this->inc_cursor();
        }
        while (true) {
            $v_5 = $this->cursor;
            $v_6 = $this->cursor;
            if (!($this->eq_s("ij"))) {
                goto lab5;
            }
            goto lab6;
        lab5:
            $this->cursor = $v_6;
            if (!($this->in_grouping(self::G_v))) {
                goto lab4;
            }
        lab6:
            continue;
        lab4:
            $this->cursor = $v_5;
            break;
        }
        if ($this->cursor < $this->limit) {
            goto lab7;
        }
        return false;
    lab7:
        $this->cursor = $v_2;
        $this->B_GE_removed = true;
        $this->slice_del();
        $v_7 = $this->cursor;
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_11);
        if (0 === $among_var) {
            goto lab8;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_from("e");
                break;
            case 2:
                $this->slice_from("i");
                break;
        }
    lab8:
        $this->cursor = $v_7;
        return true;
    }


    protected function r_measure(): bool
    {
        $this->I_p1 = $this->limit;
        $this->I_p2 = $this->limit;
        $v_1 = $this->cursor;
        while (true) {
            if (!($this->out_grouping(self::G_v))) {
                goto lab1;
            }
            continue;
        lab1:
            break;
        }
        $v_2 = 1;
        while (true) {
            $v_3 = $this->cursor;
            $v_4 = $this->cursor;
            if (!($this->eq_s("ij"))) {
                goto lab3;
            }
            goto lab4;
        lab3:
            $this->cursor = $v_4;
            if (!($this->in_grouping(self::G_v))) {
                goto lab2;
            }
        lab4:
            $v_2--;
            continue;
        lab2:
            $this->cursor = $v_3;
            break;
        }
        if ($v_2 > 0) {
            goto lab0;
        }
        if (!($this->out_grouping(self::G_v))) {
            goto lab0;
        }
        $this->I_p1 = $this->cursor;
        while (true) {
            if (!($this->out_grouping(self::G_v))) {
                goto lab5;
            }
            continue;
        lab5:
            break;
        }
        $v_5 = 1;
        while (true) {
            $v_6 = $this->cursor;
            $v_7 = $this->cursor;
            if (!($this->eq_s("ij"))) {
                goto lab7;
            }
            goto lab8;
        lab7:
            $this->cursor = $v_7;
            if (!($this->in_grouping(self::G_v))) {
                goto lab6;
            }
        lab8:
            $v_5--;
            continue;
        lab6:
            $this->cursor = $v_6;
            break;
        }
        if ($v_5 > 0) {
            goto lab0;
        }
        if (!($this->out_grouping(self::G_v))) {
            goto lab0;
        }
        $this->I_p2 = $this->cursor;
    lab0:
        $this->cursor = $v_1;
        return true;
    }


    public function stem(): bool
    {
        $B_stemmed = false;
        $this->r_measure();
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_1 = $this->limit - $this->cursor;
        if (!$this->r_Step_1()) {
            goto lab0;
        }
        $B_stemmed = true;
    lab0:
        $this->cursor = $this->limit - $v_1;
        $v_2 = $this->limit - $this->cursor;
        if (!$this->r_Step_2()) {
            goto lab1;
        }
        $B_stemmed = true;
    lab1:
        $this->cursor = $this->limit - $v_2;
        $v_3 = $this->limit - $this->cursor;
        if (!$this->r_Step_3()) {
            goto lab2;
        }
        $B_stemmed = true;
    lab2:
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        if (!$this->r_Step_4()) {
            goto lab3;
        }
        $B_stemmed = true;
    lab3:
        $this->cursor = $this->limit - $v_4;
        $this->cursor = $this->limit_backward;
        $this->B_GE_removed = false;
        $v_5 = $this->cursor;
        $v_6 = $this->cursor;
        if (!$this->r_Lose_prefix()) {
            goto lab4;
        }
        $this->cursor = $v_6;
        $this->r_measure();
    lab4:
        $this->cursor = $v_5;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_7 = $this->limit - $this->cursor;
        if (!$this->B_GE_removed) {
            goto lab5;
        }
        $B_stemmed = true;
        if (!$this->r_Step_1c()) {
            goto lab5;
        }
    lab5:
        $this->cursor = $this->limit - $v_7;
        $this->cursor = $this->limit_backward;
        $this->B_GE_removed = false;
        $v_8 = $this->cursor;
        $v_9 = $this->cursor;
        if (!$this->r_Lose_infix()) {
            goto lab6;
        }
        $this->cursor = $v_9;
        $this->r_measure();
    lab6:
        $this->cursor = $v_8;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_10 = $this->limit - $this->cursor;
        if (!$this->B_GE_removed) {
            goto lab7;
        }
        $B_stemmed = true;
        if (!$this->r_Step_1c()) {
            goto lab7;
        }
    lab7:
        $this->cursor = $this->limit - $v_10;
        $this->cursor = $this->limit_backward;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_11 = $this->limit - $this->cursor;
        if (!$this->r_Step_7()) {
            goto lab8;
        }
        $B_stemmed = true;
    lab8:
        $this->cursor = $this->limit - $v_11;
        $v_12 = $this->limit - $this->cursor;
        if (!$B_stemmed) {
            goto lab9;
        }
        if (!$this->r_Step_6()) {
            goto lab9;
        }
    lab9:
        $this->cursor = $this->limit - $v_12;
        $this->cursor = $this->limit_backward;
        return true;
    }
}
