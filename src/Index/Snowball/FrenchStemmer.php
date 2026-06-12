<?php

namespace Tag1\Scolta\Index\Snowball;
// Generated from french.sbl by Snowball 3.0.0 - https://snowballstem.org/

class FrenchStemmer extends SnowballStemmer
{
    private const A_0 = [
        ["col", -1, -1],
        ["ni", -1, 1],
        ["par", -1, -1],
        ["tap", -1, -1]
    ];

    private const A_1 = [
        ["", -1, 7],
        ["H", 0, 6],
        ["He", 1, 4],
        ["Hi", 1, 5],
        ["I", 0, 1],
        ["U", 0, 2],
        ["Y", 0, 3]
    ];

    private const A_2 = [
        ["iqU", -1, 3],
        ["abl", -1, 3],
        ["I\u{00E8}r", -1, 4],
        ["i\u{00E8}r", -1, 4],
        ["eus", -1, 2],
        ["iv", -1, 1]
    ];

    private const A_3 = [
        ["ic", -1, 2],
        ["abil", -1, 1],
        ["iv", -1, 3]
    ];

    private const A_4 = [
        ["iqUe", -1, 1],
        ["atrice", -1, 2],
        ["ance", -1, 1],
        ["ence", -1, 5],
        ["logie", -1, 3],
        ["able", -1, 1],
        ["isme", -1, 1],
        ["euse", -1, 12],
        ["iste", -1, 1],
        ["ive", -1, 8],
        ["if", -1, 8],
        ["usion", -1, 4],
        ["ation", -1, 2],
        ["ution", -1, 4],
        ["ateur", -1, 2],
        ["iqUes", -1, 1],
        ["atrices", -1, 2],
        ["ances", -1, 1],
        ["ences", -1, 5],
        ["logies", -1, 3],
        ["ables", -1, 1],
        ["ismes", -1, 1],
        ["euses", -1, 12],
        ["istes", -1, 1],
        ["ives", -1, 8],
        ["ifs", -1, 8],
        ["usions", -1, 4],
        ["ations", -1, 2],
        ["utions", -1, 4],
        ["ateurs", -1, 2],
        ["ments", -1, 16],
        ["ements", 30, 6],
        ["issements", 31, 13],
        ["it\u{00E9}s", -1, 7],
        ["ment", -1, 16],
        ["ement", 34, 6],
        ["issement", 35, 13],
        ["amment", 34, 14],
        ["emment", 34, 15],
        ["aux", -1, 10],
        ["eaux", 39, 9],
        ["eux", -1, 1],
        ["oux", -1, 11],
        ["it\u{00E9}", -1, 7]
    ];

    private const A_5 = [
        ["ira", -1, 1],
        ["ie", -1, 1],
        ["isse", -1, 1],
        ["issante", -1, 1],
        ["i", -1, 1],
        ["irai", 4, 1],
        ["ir", -1, 1],
        ["iras", -1, 1],
        ["ies", -1, 1],
        ["\u{00EE}mes", -1, 1],
        ["isses", -1, 1],
        ["issantes", -1, 1],
        ["\u{00EE}tes", -1, 1],
        ["is", -1, 1],
        ["irais", 13, 1],
        ["issais", 13, 1],
        ["irions", -1, 1],
        ["issions", -1, 1],
        ["irons", -1, 1],
        ["issons", -1, 1],
        ["issants", -1, 1],
        ["it", -1, 1],
        ["irait", 21, 1],
        ["issait", 21, 1],
        ["issant", -1, 1],
        ["iraIent", -1, 1],
        ["issaIent", -1, 1],
        ["irent", -1, 1],
        ["issent", -1, 1],
        ["iront", -1, 1],
        ["\u{00EE}t", -1, 1],
        ["iriez", -1, 1],
        ["issiez", -1, 1],
        ["irez", -1, 1],
        ["issez", -1, 1]
    ];

    private const A_6 = [
        ["al", -1, 1],
        ["\u{00E9}pl", -1, -1],
        ["auv", -1, -1]
    ];

    private const A_7 = [
        ["a", -1, 3],
        ["era", 0, 2],
        ["aise", -1, 4],
        ["asse", -1, 3],
        ["ante", -1, 3],
        ["\u{00E9}e", -1, 2],
        ["ai", -1, 3],
        ["erai", 6, 2],
        ["er", -1, 2],
        ["as", -1, 3],
        ["eras", 9, 2],
        ["\u{00E2}mes", -1, 3],
        ["aises", -1, 4],
        ["asses", -1, 3],
        ["antes", -1, 3],
        ["\u{00E2}tes", -1, 3],
        ["\u{00E9}es", -1, 2],
        ["ais", -1, 4],
        ["eais", 17, 2],
        ["erais", 17, 2],
        ["ions", -1, 1],
        ["erions", 20, 2],
        ["assions", 20, 3],
        ["erons", -1, 2],
        ["ants", -1, 3],
        ["\u{00E9}s", -1, 2],
        ["ait", -1, 3],
        ["erait", 26, 2],
        ["ant", -1, 3],
        ["aIent", -1, 3],
        ["eraIent", 29, 2],
        ["\u{00E8}rent", -1, 2],
        ["assent", -1, 3],
        ["eront", -1, 2],
        ["\u{00E2}t", -1, 3],
        ["ez", -1, 2],
        ["iez", 35, 2],
        ["eriez", 36, 2],
        ["assiez", 36, 3],
        ["erez", 35, 2],
        ["\u{00E9}", -1, 2]
    ];

    private const A_8 = [
        ["e", -1, 3],
        ["I\u{00E8}re", 0, 2],
        ["i\u{00E8}re", 0, 2],
        ["ion", -1, 1],
        ["Ier", -1, 2],
        ["ier", -1, 2]
    ];

    private const A_9 = [
        ["ell", -1, -1],
        ["eill", -1, -1],
        ["enn", -1, -1],
        ["onn", -1, -1],
        ["ett", -1, -1]
    ];

    private const G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "y"=>true, "\u{00E0}"=>true, "\u{00E2}"=>true, "\u{00E8}"=>true, "\u{00E9}"=>true, "\u{00EA}"=>true, "\u{00EB}"=>true, "\u{00EE}"=>true, "\u{00EF}"=>true, "\u{00F4}"=>true, "\u{00F9}"=>true, "\u{00FB}"=>true];

    private const G_oux_ending = ["b"=>true, "h"=>true, "j"=>true, "l"=>true, "n"=>true, "p"=>true];

    private const G_elision_char = ["c"=>true, "d"=>true, "j"=>true, "l"=>true, "m"=>true, "n"=>true, "s"=>true, "t"=>true];

    private const G_keep_with_s = ["a"=>true, "i"=>true, "o"=>true, "s"=>true, "u"=>true, "\u{00E8}"=>true];

    private int $I_p2 = 0;
    private int $I_p1 = 0;
    private int $I_pV = 0;



    protected function r_elisions(): bool
    {
        $this->bra = $this->cursor;
        $v_1 = $this->cursor;
        if (!($this->in_grouping(self::G_elision_char))) {
            goto lab0;
        }
        goto lab1;
    lab0:
        $this->cursor = $v_1;
        if (!($this->eq_s("qu"))) {
            return false;
        }
    lab1:
        if (!($this->eq_s("'"))) {
            return false;
        }
        $this->ket = $this->cursor;
        if ($this->cursor < $this->limit) {
            goto lab2;
        }
        return false;
    lab2:
        $this->slice_del();
        return true;
    }


    protected function r_prelude(): bool
    {
        while (true) {
            $v_1 = $this->cursor;
            while (true) {
                $v_2 = $this->cursor;
                $v_3 = $this->cursor;
                if (!($this->in_grouping(self::G_v))) {
                    goto lab2;
                }
                $this->bra = $this->cursor;
                $v_4 = $this->cursor;
                if (!($this->eq_s("u"))) {
                    goto lab3;
                }
                $this->ket = $this->cursor;
                if (!($this->in_grouping(self::G_v))) {
                    goto lab3;
                }
                $this->slice_from("U");
                goto lab4;
            lab3:
                $this->cursor = $v_4;
                if (!($this->eq_s("i"))) {
                    goto lab5;
                }
                $this->ket = $this->cursor;
                if (!($this->in_grouping(self::G_v))) {
                    goto lab5;
                }
                $this->slice_from("I");
                goto lab4;
            lab5:
                $this->cursor = $v_4;
                if (!($this->eq_s("y"))) {
                    goto lab2;
                }
                $this->ket = $this->cursor;
                $this->slice_from("Y");
            lab4:
                goto lab6;
            lab2:
                $this->cursor = $v_3;
                $this->bra = $this->cursor;
                if (!($this->eq_s("\u{00EB}"))) {
                    goto lab7;
                }
                $this->ket = $this->cursor;
                $this->slice_from("He");
                goto lab6;
            lab7:
                $this->cursor = $v_3;
                $this->bra = $this->cursor;
                if (!($this->eq_s("\u{00EF}"))) {
                    goto lab8;
                }
                $this->ket = $this->cursor;
                $this->slice_from("Hi");
                goto lab6;
            lab8:
                $this->cursor = $v_3;
                $this->bra = $this->cursor;
                if (!($this->eq_s("y"))) {
                    goto lab9;
                }
                $this->ket = $this->cursor;
                if (!($this->in_grouping(self::G_v))) {
                    goto lab9;
                }
                $this->slice_from("Y");
                goto lab6;
            lab9:
                $this->cursor = $v_3;
                if (!($this->eq_s("q"))) {
                    goto lab1;
                }
                $this->bra = $this->cursor;
                if (!($this->eq_s("u"))) {
                    goto lab1;
                }
                $this->ket = $this->cursor;
                $this->slice_from("U");
            lab6:
                $this->cursor = $v_2;
                break;
            lab1:
                $this->cursor = $v_2;
                if ($this->cursor >= $this->limit) {
                    goto lab0;
                }
                $this->inc_cursor();
            }
            continue;
        lab0:
            $this->cursor = $v_1;
            break;
        }
        return true;
    }


    protected function r_mark_regions(): bool
    {
        $this->I_pV = $this->limit;
        $this->I_p1 = $this->limit;
        $this->I_p2 = $this->limit;
        $v_1 = $this->cursor;
        $v_2 = $this->cursor;
        if (!($this->in_grouping(self::G_v))) {
            goto lab1;
        }
        if (!($this->in_grouping(self::G_v))) {
            goto lab1;
        }
        if ($this->cursor >= $this->limit) {
            goto lab1;
        }
        $this->inc_cursor();
        goto lab2;
    lab1:
        $this->cursor = $v_2;
        $among_var = $this->find_among(self::A_0);
        if (0 === $among_var) {
            goto lab3;
        }
        switch ($among_var) {
            case 1:
                if (!($this->in_grouping(self::G_v))) {
                    goto lab3;
                }
                break;
        }
        goto lab2;
    lab3:
        $this->cursor = $v_2;
        if ($this->cursor >= $this->limit) {
            goto lab0;
        }
        $this->inc_cursor();
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
    lab2:
        $this->I_pV = $this->cursor;
    lab0:
        $this->cursor = $v_1;
        $v_3 = $this->cursor;
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab4;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab4;
        }
        $this->inc_cursor();
        $this->I_p1 = $this->cursor;
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab4;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab4;
        }
        $this->inc_cursor();
        $this->I_p2 = $this->cursor;
    lab4:
        $this->cursor = $v_3;
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
                    $this->slice_from("i");
                    break;
                case 2:
                    $this->slice_from("u");
                    break;
                case 3:
                    $this->slice_from("y");
                    break;
                case 4:
                    $this->slice_from("\u{00EB}");
                    break;
                case 5:
                    $this->slice_from("\u{00EF}");
                    break;
                case 6:
                    $this->slice_del();
                    break;
                case 7:
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


    protected function r_RV(): bool
    {
        return $this->I_pV <= $this->cursor;
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
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_4);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                $v_1 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                if (!($this->eq_s_b("ic"))) {
                    $this->cursor = $this->limit - $v_1;
                    goto lab0;
                }
                $this->bra = $this->cursor;
                $v_2 = $this->limit - $this->cursor;
                if (!$this->r_R2()) {
                    goto lab1;
                }
                $this->slice_del();
                goto lab2;
            lab1:
                $this->cursor = $this->limit - $v_2;
                $this->slice_from("iqU");
            lab2:
            lab0:
                break;
            case 3:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_from("log");
                break;
            case 4:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_from("u");
                break;
            case 5:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_from("ent");
                break;
            case 6:
                if (!$this->r_RV()) {
                    return false;
                }
                $this->slice_del();
                $v_3 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                $among_var = $this->find_among_b(self::A_2);
                if (0 === $among_var) {
                    $this->cursor = $this->limit - $v_3;
                    goto lab3;
                }
                $this->bra = $this->cursor;
                switch ($among_var) {
                    case 1:
                        if (!$this->r_R2()) {
                            $this->cursor = $this->limit - $v_3;
                            goto lab3;
                        }
                        $this->slice_del();
                        $this->ket = $this->cursor;
                        if (!($this->eq_s_b("at"))) {
                            $this->cursor = $this->limit - $v_3;
                            goto lab3;
                        }
                        $this->bra = $this->cursor;
                        if (!$this->r_R2()) {
                            $this->cursor = $this->limit - $v_3;
                            goto lab3;
                        }
                        $this->slice_del();
                        break;
                    case 2:
                        $v_4 = $this->limit - $this->cursor;
                        if (!$this->r_R2()) {
                            goto lab4;
                        }
                        $this->slice_del();
                        goto lab5;
                    lab4:
                        $this->cursor = $this->limit - $v_4;
                        if (!$this->r_R1()) {
                            $this->cursor = $this->limit - $v_3;
                            goto lab3;
                        }
                        $this->slice_from("eux");
                    lab5:
                        break;
                    case 3:
                        if (!$this->r_R2()) {
                            $this->cursor = $this->limit - $v_3;
                            goto lab3;
                        }
                        $this->slice_del();
                        break;
                    case 4:
                        if (!$this->r_RV()) {
                            $this->cursor = $this->limit - $v_3;
                            goto lab3;
                        }
                        $this->slice_from("i");
                        break;
                }
            lab3:
                break;
            case 7:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                $v_5 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                $among_var = $this->find_among_b(self::A_3);
                if (0 === $among_var) {
                    $this->cursor = $this->limit - $v_5;
                    goto lab6;
                }
                $this->bra = $this->cursor;
                switch ($among_var) {
                    case 1:
                        $v_6 = $this->limit - $this->cursor;
                        if (!$this->r_R2()) {
                            goto lab7;
                        }
                        $this->slice_del();
                        goto lab8;
                    lab7:
                        $this->cursor = $this->limit - $v_6;
                        $this->slice_from("abl");
                    lab8:
                        break;
                    case 2:
                        $v_7 = $this->limit - $this->cursor;
                        if (!$this->r_R2()) {
                            goto lab9;
                        }
                        $this->slice_del();
                        goto lab10;
                    lab9:
                        $this->cursor = $this->limit - $v_7;
                        $this->slice_from("iqU");
                    lab10:
                        break;
                    case 3:
                        if (!$this->r_R2()) {
                            $this->cursor = $this->limit - $v_5;
                            goto lab6;
                        }
                        $this->slice_del();
                        break;
                }
            lab6:
                break;
            case 8:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                $v_8 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                if (!($this->eq_s_b("at"))) {
                    $this->cursor = $this->limit - $v_8;
                    goto lab11;
                }
                $this->bra = $this->cursor;
                if (!$this->r_R2()) {
                    $this->cursor = $this->limit - $v_8;
                    goto lab11;
                }
                $this->slice_del();
                $this->ket = $this->cursor;
                if (!($this->eq_s_b("ic"))) {
                    $this->cursor = $this->limit - $v_8;
                    goto lab11;
                }
                $this->bra = $this->cursor;
                $v_9 = $this->limit - $this->cursor;
                if (!$this->r_R2()) {
                    goto lab12;
                }
                $this->slice_del();
                goto lab13;
            lab12:
                $this->cursor = $this->limit - $v_9;
                $this->slice_from("iqU");
            lab13:
            lab11:
                break;
            case 9:
                $this->slice_from("eau");
                break;
            case 10:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_from("al");
                break;
            case 11:
                if (!($this->in_grouping_b(self::G_oux_ending))) {
                    return false;
                }
                $this->slice_from("ou");
                break;
            case 12:
                $v_10 = $this->limit - $this->cursor;
                if (!$this->r_R2()) {
                    goto lab14;
                }
                $this->slice_del();
                goto lab15;
            lab14:
                $this->cursor = $this->limit - $v_10;
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_from("eux");
            lab15:
                break;
            case 13:
                if (!$this->r_R1()) {
                    return false;
                }
                if (!($this->out_grouping_b(self::G_v))) {
                    return false;
                }
                $this->slice_del();
                break;
            case 14:
                if (!$this->r_RV()) {
                    return false;
                }
                $this->slice_from("ant");
                return false;
            case 15:
                if (!$this->r_RV()) {
                    return false;
                }
                $this->slice_from("ent");
                return false;
            case 16:
                $v_11 = $this->limit - $this->cursor;
                if (!($this->in_grouping_b(self::G_v))) {
                    return false;
                }
                if (!$this->r_RV()) {
                    return false;
                }
                $this->cursor = $this->limit - $v_11;
                $this->slice_del();
                return false;
        }
        return true;
    }


    protected function r_i_verb_suffix(): bool
    {
        if ($this->cursor < $this->I_pV) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_pV;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_5) === 0) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $v_2 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("H"))) {
            goto lab0;
        }
        $this->limit_backward = $v_1;
        return false;
    lab0:
        $this->cursor = $this->limit - $v_2;
        if (!($this->out_grouping_b(self::G_v))) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->slice_del();
        $this->limit_backward = $v_1;
        return true;
    }


    protected function r_verb_suffix(): bool
    {
        if ($this->cursor < $this->I_pV) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_pV;
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
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                $this->slice_del();
                break;
            case 3:
                $v_2 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("e"))) {
                    $this->cursor = $this->limit - $v_2;
                    goto lab0;
                }
                if (!$this->r_RV()) {
                    $this->cursor = $this->limit - $v_2;
                    goto lab0;
                }
                $this->bra = $this->cursor;
            lab0:
                $this->slice_del();
                break;
            case 4:
                $v_3 = $this->limit - $this->cursor;
                $among_var = $this->find_among_b(self::A_6);
                if (0 === $among_var) {
                    goto lab1;
                }
                switch ($among_var) {
                    case 1:
                        if ($this->cursor <= $this->limit_backward) {
                            goto lab1;
                        }
                        $this->dec_cursor();
                        if ($this->cursor > $this->limit_backward) {
                            goto lab1;
                        }
                        break;
                }
                return false;
            lab1:
                $this->cursor = $this->limit - $v_3;
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_residual_suffix(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("s"))) {
            $this->cursor = $this->limit - $v_1;
            goto lab0;
        }
        $this->bra = $this->cursor;
        $v_2 = $this->limit - $this->cursor;
        $v_3 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("Hi"))) {
            goto lab1;
        }
        goto lab2;
    lab1:
        $this->cursor = $this->limit - $v_3;
        if (!($this->out_grouping_b(self::G_keep_with_s))) {
            $this->cursor = $this->limit - $v_1;
            goto lab0;
        }
    lab2:
        $this->cursor = $this->limit - $v_2;
        $this->slice_del();
    lab0:
        if ($this->cursor < $this->I_pV) {
            return false;
        }
        $v_4 = $this->limit_backward;
        $this->limit_backward = $this->I_pV;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_8);
        if (0 === $among_var) {
            $this->limit_backward = $v_4;
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_R2()) {
                    $this->limit_backward = $v_4;
                    return false;
                }
                $v_5 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("s"))) {
                    goto lab3;
                }
                goto lab4;
            lab3:
                $this->cursor = $this->limit - $v_5;
                if (!($this->eq_s_b("t"))) {
                    $this->limit_backward = $v_4;
                    return false;
                }
            lab4:
                $this->slice_del();
                break;
            case 2:
                $this->slice_from("i");
                break;
            case 3:
                $this->slice_del();
                break;
        }
        $this->limit_backward = $v_4;
        return true;
    }


    protected function r_un_double(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        if ($this->find_among_b(self::A_9) === 0) {
            return false;
        }
        $this->cursor = $this->limit - $v_1;
        $this->ket = $this->cursor;
        if ($this->cursor <= $this->limit_backward) {
            return false;
        }
        $this->dec_cursor();
        $this->bra = $this->cursor;
        $this->slice_del();
        return true;
    }


    protected function r_un_accent(): bool
    {
        $v_1 = 1;
        while (true) {
            if (!($this->out_grouping_b(self::G_v))) {
                goto lab0;
            }
            $v_1--;
            continue;
        lab0:
            break;
        }
        if ($v_1 > 0) {
            return false;
        }
        $this->ket = $this->cursor;
        $v_2 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("\u{00E9}"))) {
            goto lab1;
        }
        goto lab2;
    lab1:
        $this->cursor = $this->limit - $v_2;
        if (!($this->eq_s_b("\u{00E8}"))) {
            return false;
        }
    lab2:
        $this->bra = $this->cursor;
        $this->slice_from("e");
        return true;
    }


    public function stem(): bool
    {
        $v_1 = $this->cursor;
        $this->r_elisions();
        $this->cursor = $v_1;
        $v_2 = $this->cursor;
        $this->r_prelude();
        $this->cursor = $v_2;
        $this->r_mark_regions();
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_3 = $this->limit - $this->cursor;
        $v_4 = $this->limit - $this->cursor;
        $v_5 = $this->limit - $this->cursor;
        $v_6 = $this->limit - $this->cursor;
        if (!$this->r_standard_suffix()) {
            goto lab2;
        }
        goto lab3;
    lab2:
        $this->cursor = $this->limit - $v_6;
        if (!$this->r_i_verb_suffix()) {
            goto lab4;
        }
        goto lab3;
    lab4:
        $this->cursor = $this->limit - $v_6;
        if (!$this->r_verb_suffix()) {
            goto lab1;
        }
    lab3:
        $this->cursor = $this->limit - $v_5;
        $v_7 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $v_8 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("Y"))) {
            goto lab6;
        }
        $this->bra = $this->cursor;
        $this->slice_from("i");
        goto lab7;
    lab6:
        $this->cursor = $this->limit - $v_8;
        if (!($this->eq_s_b("\u{00E7}"))) {
            $this->cursor = $this->limit - $v_7;
            goto lab5;
        }
        $this->bra = $this->cursor;
        $this->slice_from("c");
    lab7:
    lab5:
        goto lab8;
    lab1:
        $this->cursor = $this->limit - $v_4;
        if (!$this->r_residual_suffix()) {
            goto lab0;
        }
    lab8:
    lab0:
        $this->cursor = $this->limit - $v_3;
        $v_9 = $this->limit - $this->cursor;
        $this->r_un_double();
        $this->cursor = $this->limit - $v_9;
        $v_10 = $this->limit - $this->cursor;
        $this->r_un_accent();
        $this->cursor = $this->limit - $v_10;
        $this->cursor = $this->limit_backward;
        $v_11 = $this->cursor;
        $this->r_postlude();
        $this->cursor = $v_11;
        return true;
    }
}
