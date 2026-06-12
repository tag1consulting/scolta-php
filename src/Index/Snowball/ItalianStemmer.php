<?php

namespace Tag1\Scolta\Index\Snowball;
// Generated from italian.sbl by Snowball 3.0.0 - https://snowballstem.org/

class ItalianStemmer extends SnowballStemmer
{
    private const A_0 = [
        ["", -1, 7],
        ["qu", 0, 6],
        ["\u{00E1}", 0, 1],
        ["\u{00E9}", 0, 2],
        ["\u{00ED}", 0, 3],
        ["\u{00F3}", 0, 4],
        ["\u{00FA}", 0, 5]
    ];

    private const A_1 = [
        ["", -1, 3],
        ["I", 0, 1],
        ["U", 0, 2]
    ];

    private const A_2 = [
        ["la", -1, -1],
        ["cela", 0, -1],
        ["gliela", 0, -1],
        ["mela", 0, -1],
        ["tela", 0, -1],
        ["vela", 0, -1],
        ["le", -1, -1],
        ["cele", 6, -1],
        ["gliele", 6, -1],
        ["mele", 6, -1],
        ["tele", 6, -1],
        ["vele", 6, -1],
        ["ne", -1, -1],
        ["cene", 12, -1],
        ["gliene", 12, -1],
        ["mene", 12, -1],
        ["sene", 12, -1],
        ["tene", 12, -1],
        ["vene", 12, -1],
        ["ci", -1, -1],
        ["li", -1, -1],
        ["celi", 20, -1],
        ["glieli", 20, -1],
        ["meli", 20, -1],
        ["teli", 20, -1],
        ["veli", 20, -1],
        ["gli", 20, -1],
        ["mi", -1, -1],
        ["si", -1, -1],
        ["ti", -1, -1],
        ["vi", -1, -1],
        ["lo", -1, -1],
        ["celo", 31, -1],
        ["glielo", 31, -1],
        ["melo", 31, -1],
        ["telo", 31, -1],
        ["velo", 31, -1]
    ];

    private const A_3 = [
        ["ando", -1, 1],
        ["endo", -1, 1],
        ["ar", -1, 2],
        ["er", -1, 2],
        ["ir", -1, 2]
    ];

    private const A_4 = [
        ["ic", -1, -1],
        ["abil", -1, -1],
        ["os", -1, -1],
        ["iv", -1, 1]
    ];

    private const A_5 = [
        ["ic", -1, 1],
        ["abil", -1, 1],
        ["iv", -1, 1]
    ];

    private const A_6 = [
        ["ica", -1, 1],
        ["logia", -1, 3],
        ["osa", -1, 1],
        ["ista", -1, 1],
        ["iva", -1, 9],
        ["anza", -1, 1],
        ["enza", -1, 5],
        ["ice", -1, 1],
        ["atrice", 7, 1],
        ["iche", -1, 1],
        ["logie", -1, 3],
        ["abile", -1, 1],
        ["ibile", -1, 1],
        ["usione", -1, 4],
        ["azione", -1, 2],
        ["uzione", -1, 4],
        ["atore", -1, 2],
        ["ose", -1, 1],
        ["ante", -1, 1],
        ["mente", -1, 1],
        ["amente", 19, 7],
        ["iste", -1, 1],
        ["ive", -1, 9],
        ["anze", -1, 1],
        ["enze", -1, 5],
        ["ici", -1, 1],
        ["atrici", 25, 1],
        ["ichi", -1, 1],
        ["abili", -1, 1],
        ["ibili", -1, 1],
        ["ismi", -1, 1],
        ["usioni", -1, 4],
        ["azioni", -1, 2],
        ["uzioni", -1, 4],
        ["atori", -1, 2],
        ["osi", -1, 1],
        ["anti", -1, 1],
        ["amenti", -1, 6],
        ["imenti", -1, 6],
        ["isti", -1, 1],
        ["ivi", -1, 9],
        ["ico", -1, 1],
        ["ismo", -1, 1],
        ["oso", -1, 1],
        ["amento", -1, 6],
        ["imento", -1, 6],
        ["ivo", -1, 9],
        ["it\u{00E0}", -1, 8],
        ["ist\u{00E0}", -1, 1],
        ["ist\u{00E8}", -1, 1],
        ["ist\u{00EC}", -1, 1]
    ];

    private const A_7 = [
        ["isca", -1, 1],
        ["enda", -1, 1],
        ["ata", -1, 1],
        ["ita", -1, 1],
        ["uta", -1, 1],
        ["ava", -1, 1],
        ["eva", -1, 1],
        ["iva", -1, 1],
        ["erebbe", -1, 1],
        ["irebbe", -1, 1],
        ["isce", -1, 1],
        ["ende", -1, 1],
        ["are", -1, 1],
        ["ere", -1, 1],
        ["ire", -1, 1],
        ["asse", -1, 1],
        ["ate", -1, 1],
        ["avate", 16, 1],
        ["evate", 16, 1],
        ["ivate", 16, 1],
        ["ete", -1, 1],
        ["erete", 20, 1],
        ["irete", 20, 1],
        ["ite", -1, 1],
        ["ereste", -1, 1],
        ["ireste", -1, 1],
        ["ute", -1, 1],
        ["erai", -1, 1],
        ["irai", -1, 1],
        ["isci", -1, 1],
        ["endi", -1, 1],
        ["erei", -1, 1],
        ["irei", -1, 1],
        ["assi", -1, 1],
        ["ati", -1, 1],
        ["iti", -1, 1],
        ["eresti", -1, 1],
        ["iresti", -1, 1],
        ["uti", -1, 1],
        ["avi", -1, 1],
        ["evi", -1, 1],
        ["ivi", -1, 1],
        ["isco", -1, 1],
        ["ando", -1, 1],
        ["endo", -1, 1],
        ["Yamo", -1, 1],
        ["iamo", -1, 1],
        ["avamo", -1, 1],
        ["evamo", -1, 1],
        ["ivamo", -1, 1],
        ["eremo", -1, 1],
        ["iremo", -1, 1],
        ["assimo", -1, 1],
        ["ammo", -1, 1],
        ["emmo", -1, 1],
        ["eremmo", 54, 1],
        ["iremmo", 54, 1],
        ["immo", -1, 1],
        ["ano", -1, 1],
        ["iscano", 58, 1],
        ["avano", 58, 1],
        ["evano", 58, 1],
        ["ivano", 58, 1],
        ["eranno", -1, 1],
        ["iranno", -1, 1],
        ["ono", -1, 1],
        ["iscono", 65, 1],
        ["arono", 65, 1],
        ["erono", 65, 1],
        ["irono", 65, 1],
        ["erebbero", -1, 1],
        ["irebbero", -1, 1],
        ["assero", -1, 1],
        ["essero", -1, 1],
        ["issero", -1, 1],
        ["ato", -1, 1],
        ["ito", -1, 1],
        ["uto", -1, 1],
        ["avo", -1, 1],
        ["evo", -1, 1],
        ["ivo", -1, 1],
        ["ar", -1, 1],
        ["ir", -1, 1],
        ["er\u{00E0}", -1, 1],
        ["ir\u{00E0}", -1, 1],
        ["er\u{00F2}", -1, 1],
        ["ir\u{00F2}", -1, 1]
    ];

    private const G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "\u{00E0}"=>true, "\u{00E8}"=>true, "\u{00EC}"=>true, "\u{00F2}"=>true, "\u{00F9}"=>true];

    private const G_AEIO = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "\u{00E0}"=>true, "\u{00E8}"=>true, "\u{00EC}"=>true, "\u{00F2}"=>true];

    private const G_CG = ["c"=>true, "g"=>true];

    private int $I_p2 = 0;
    private int $I_p1 = 0;
    private int $I_pV = 0;



    protected function r_prelude(): bool
    {
        $v_1 = $this->cursor;
        while (true) {
            $v_2 = $this->cursor;
            $this->bra = $this->cursor;
            $among_var = $this->find_among(self::A_0);
            $this->ket = $this->cursor;
            switch ($among_var) {
                case 1:
                    $this->slice_from("\u{00E0}");
                    break;
                case 2:
                    $this->slice_from("\u{00E8}");
                    break;
                case 3:
                    $this->slice_from("\u{00EC}");
                    break;
                case 4:
                    $this->slice_from("\u{00F2}");
                    break;
                case 5:
                    $this->slice_from("\u{00F9}");
                    break;
                case 6:
                    $this->slice_from("qU");
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
            $this->cursor = $v_2;
            break;
        }
        $this->cursor = $v_1;
        while (true) {
            $v_3 = $this->cursor;
            while (true) {
                $v_4 = $this->cursor;
                if (!($this->in_grouping(self::G_v))) {
                    goto lab2;
                }
                $this->bra = $this->cursor;
                $v_5 = $this->cursor;
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
                $this->cursor = $v_5;
                if (!($this->eq_s("i"))) {
                    goto lab2;
                }
                $this->ket = $this->cursor;
                if (!($this->in_grouping(self::G_v))) {
                    goto lab2;
                }
                $this->slice_from("I");
            lab4:
                $this->cursor = $v_4;
                break;
            lab2:
                $this->cursor = $v_4;
                if ($this->cursor >= $this->limit) {
                    goto lab1;
                }
                $this->inc_cursor();
            }
            continue;
        lab1:
            $this->cursor = $v_3;
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
        $v_3 = $this->cursor;
        if (!($this->out_grouping(self::G_v))) {
            goto lab2;
        }
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab2;
        }
        $this->inc_cursor();
        goto lab3;
    lab2:
        $this->cursor = $v_3;
        if (!($this->in_grouping(self::G_v))) {
            goto lab1;
        }
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab1;
        }
        $this->inc_cursor();
    lab3:
        goto lab4;
    lab1:
        $this->cursor = $v_2;
        if (!($this->eq_s("divan"))) {
            goto lab5;
        }
        goto lab4;
    lab5:
        $this->cursor = $v_2;
        if (!($this->out_grouping(self::G_v))) {
            goto lab0;
        }
        $v_4 = $this->cursor;
        if (!($this->out_grouping(self::G_v))) {
            goto lab6;
        }
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab6;
        }
        $this->inc_cursor();
        goto lab7;
    lab6:
        $this->cursor = $v_4;
        if (!($this->in_grouping(self::G_v))) {
            goto lab0;
        }
        if ($this->cursor >= $this->limit) {
            goto lab0;
        }
        $this->inc_cursor();
    lab7:
    lab4:
        $this->I_pV = $this->cursor;
    lab0:
        $this->cursor = $v_1;
        $v_5 = $this->cursor;
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab8;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab8;
        }
        $this->inc_cursor();
        $this->I_p1 = $this->cursor;
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab8;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab8;
        }
        $this->inc_cursor();
        $this->I_p2 = $this->cursor;
    lab8:
        $this->cursor = $v_5;
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


    protected function r_attached_pronoun(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_2) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        $among_var = $this->find_among_b(self::A_3);
        if (0 === $among_var) {
            return false;
        }
        if (!$this->r_RV()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $this->slice_from("e");
                break;
        }
        return true;
    }


    protected function r_standard_suffix(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_6);
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
                if (!$this->r_R2()) {
                    $this->cursor = $this->limit - $v_1;
                    goto lab0;
                }
                $this->slice_del();
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
                $this->slice_from("ente");
                break;
            case 6:
                if (!$this->r_RV()) {
                    return false;
                }
                $this->slice_del();
                break;
            case 7:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_del();
                $v_2 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                $among_var = $this->find_among_b(self::A_4);
                if (0 === $among_var) {
                    $this->cursor = $this->limit - $v_2;
                    goto lab1;
                }
                $this->bra = $this->cursor;
                if (!$this->r_R2()) {
                    $this->cursor = $this->limit - $v_2;
                    goto lab1;
                }
                $this->slice_del();
                switch ($among_var) {
                    case 1:
                        $this->ket = $this->cursor;
                        if (!($this->eq_s_b("at"))) {
                            $this->cursor = $this->limit - $v_2;
                            goto lab1;
                        }
                        $this->bra = $this->cursor;
                        if (!$this->r_R2()) {
                            $this->cursor = $this->limit - $v_2;
                            goto lab1;
                        }
                        $this->slice_del();
                        break;
                }
            lab1:
                break;
            case 8:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                $v_3 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                if ($this->find_among_b(self::A_5) === 0) {
                    $this->cursor = $this->limit - $v_3;
                    goto lab2;
                }
                $this->bra = $this->cursor;
                if (!$this->r_R2()) {
                    $this->cursor = $this->limit - $v_3;
                    goto lab2;
                }
                $this->slice_del();
            lab2:
                break;
            case 9:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                $v_4 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                if (!($this->eq_s_b("at"))) {
                    $this->cursor = $this->limit - $v_4;
                    goto lab3;
                }
                $this->bra = $this->cursor;
                if (!$this->r_R2()) {
                    $this->cursor = $this->limit - $v_4;
                    goto lab3;
                }
                $this->slice_del();
                $this->ket = $this->cursor;
                if (!($this->eq_s_b("ic"))) {
                    $this->cursor = $this->limit - $v_4;
                    goto lab3;
                }
                $this->bra = $this->cursor;
                if (!$this->r_R2()) {
                    $this->cursor = $this->limit - $v_4;
                    goto lab3;
                }
                $this->slice_del();
            lab3:
                break;
        }
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
        if ($this->find_among_b(self::A_7) === 0) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $this->limit_backward = $v_1;
        return true;
    }


    protected function r_vowel_suffix(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!($this->in_grouping_b(self::G_AEIO))) {
            $this->cursor = $this->limit - $v_1;
            goto lab0;
        }
        $this->bra = $this->cursor;
        if (!$this->r_RV()) {
            $this->cursor = $this->limit - $v_1;
            goto lab0;
        }
        $this->slice_del();
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("i"))) {
            $this->cursor = $this->limit - $v_1;
            goto lab0;
        }
        $this->bra = $this->cursor;
        if (!$this->r_RV()) {
            $this->cursor = $this->limit - $v_1;
            goto lab0;
        }
        $this->slice_del();
    lab0:
        $v_2 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("h"))) {
            $this->cursor = $this->limit - $v_2;
            goto lab1;
        }
        $this->bra = $this->cursor;
        if (!($this->in_grouping_b(self::G_CG))) {
            $this->cursor = $this->limit - $v_2;
            goto lab1;
        }
        if (!$this->r_RV()) {
            $this->cursor = $this->limit - $v_2;
            goto lab1;
        }
        $this->slice_del();
    lab1:
        return true;
    }


    public function stem(): bool
    {
        $v_1 = $this->cursor;
        $this->r_prelude();
        $this->cursor = $v_1;
        $this->r_mark_regions();
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_2 = $this->limit - $this->cursor;
        $this->r_attached_pronoun();
        $this->cursor = $this->limit - $v_2;
        $v_3 = $this->limit - $this->cursor;
        $v_4 = $this->limit - $this->cursor;
        if (!$this->r_standard_suffix()) {
            goto lab1;
        }
        goto lab2;
    lab1:
        $this->cursor = $this->limit - $v_4;
        if (!$this->r_verb_suffix()) {
            goto lab0;
        }
    lab2:
    lab0:
        $this->cursor = $this->limit - $v_3;
        $v_5 = $this->limit - $this->cursor;
        $this->r_vowel_suffix();
        $this->cursor = $this->limit - $v_5;
        $this->cursor = $this->limit_backward;
        $v_6 = $this->cursor;
        $this->r_postlude();
        $this->cursor = $v_6;
        return true;
    }
}
