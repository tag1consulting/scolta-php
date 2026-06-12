<?php

namespace Tag1\Scolta\Index\Snowball;
// Generated from spanish.sbl by Snowball 3.0.0 - https://snowballstem.org/

class SpanishStemmer extends SnowballStemmer
{
    private const A_0 = [
        ["", -1, 6],
        ["\u{00E1}", 0, 1],
        ["\u{00E9}", 0, 2],
        ["\u{00ED}", 0, 3],
        ["\u{00F3}", 0, 4],
        ["\u{00FA}", 0, 5]
    ];

    private const A_1 = [
        ["la", -1, -1],
        ["sela", 0, -1],
        ["le", -1, -1],
        ["me", -1, -1],
        ["se", -1, -1],
        ["lo", -1, -1],
        ["selo", 5, -1],
        ["las", -1, -1],
        ["selas", 7, -1],
        ["les", -1, -1],
        ["los", -1, -1],
        ["selos", 10, -1],
        ["nos", -1, -1]
    ];

    private const A_2 = [
        ["ando", -1, 6],
        ["iendo", -1, 6],
        ["yendo", -1, 7],
        ["\u{00E1}ndo", -1, 2],
        ["i\u{00E9}ndo", -1, 1],
        ["ar", -1, 6],
        ["er", -1, 6],
        ["ir", -1, 6],
        ["\u{00E1}r", -1, 3],
        ["\u{00E9}r", -1, 4],
        ["\u{00ED}r", -1, 5]
    ];

    private const A_3 = [
        ["ic", -1, -1],
        ["ad", -1, -1],
        ["os", -1, -1],
        ["iv", -1, 1]
    ];

    private const A_4 = [
        ["able", -1, 1],
        ["ible", -1, 1],
        ["ante", -1, 1]
    ];

    private const A_5 = [
        ["ic", -1, 1],
        ["abil", -1, 1],
        ["iv", -1, 1]
    ];

    private const A_6 = [
        ["ica", -1, 1],
        ["ancia", -1, 2],
        ["encia", -1, 5],
        ["adora", -1, 2],
        ["osa", -1, 1],
        ["ista", -1, 1],
        ["iva", -1, 9],
        ["anza", -1, 1],
        ["log\u{00ED}a", -1, 3],
        ["idad", -1, 8],
        ["able", -1, 1],
        ["ible", -1, 1],
        ["ante", -1, 2],
        ["mente", -1, 7],
        ["amente", 13, 6],
        ["acion", -1, 2],
        ["ucion", -1, 4],
        ["aci\u{00F3}n", -1, 2],
        ["uci\u{00F3}n", -1, 4],
        ["ico", -1, 1],
        ["ismo", -1, 1],
        ["oso", -1, 1],
        ["amiento", -1, 1],
        ["imiento", -1, 1],
        ["ivo", -1, 9],
        ["ador", -1, 2],
        ["icas", -1, 1],
        ["ancias", -1, 2],
        ["encias", -1, 5],
        ["adoras", -1, 2],
        ["osas", -1, 1],
        ["istas", -1, 1],
        ["ivas", -1, 9],
        ["anzas", -1, 1],
        ["log\u{00ED}as", -1, 3],
        ["idades", -1, 8],
        ["ables", -1, 1],
        ["ibles", -1, 1],
        ["aciones", -1, 2],
        ["uciones", -1, 4],
        ["adores", -1, 2],
        ["antes", -1, 2],
        ["icos", -1, 1],
        ["ismos", -1, 1],
        ["osos", -1, 1],
        ["amientos", -1, 1],
        ["imientos", -1, 1],
        ["ivos", -1, 9]
    ];

    private const A_7 = [
        ["ya", -1, 1],
        ["ye", -1, 1],
        ["yan", -1, 1],
        ["yen", -1, 1],
        ["yeron", -1, 1],
        ["yendo", -1, 1],
        ["yo", -1, 1],
        ["yas", -1, 1],
        ["yes", -1, 1],
        ["yais", -1, 1],
        ["yamos", -1, 1],
        ["y\u{00F3}", -1, 1]
    ];

    private const A_8 = [
        ["aba", -1, 2],
        ["ada", -1, 2],
        ["ida", -1, 2],
        ["ara", -1, 2],
        ["iera", -1, 2],
        ["\u{00ED}a", -1, 2],
        ["ar\u{00ED}a", 5, 2],
        ["er\u{00ED}a", 5, 2],
        ["ir\u{00ED}a", 5, 2],
        ["ad", -1, 2],
        ["ed", -1, 2],
        ["id", -1, 2],
        ["ase", -1, 2],
        ["iese", -1, 2],
        ["aste", -1, 2],
        ["iste", -1, 2],
        ["an", -1, 2],
        ["aban", 16, 2],
        ["aran", 16, 2],
        ["ieran", 16, 2],
        ["\u{00ED}an", 16, 2],
        ["ar\u{00ED}an", 20, 2],
        ["er\u{00ED}an", 20, 2],
        ["ir\u{00ED}an", 20, 2],
        ["en", -1, 1],
        ["asen", 24, 2],
        ["iesen", 24, 2],
        ["aron", -1, 2],
        ["ieron", -1, 2],
        ["ar\u{00E1}n", -1, 2],
        ["er\u{00E1}n", -1, 2],
        ["ir\u{00E1}n", -1, 2],
        ["ado", -1, 2],
        ["ido", -1, 2],
        ["ando", -1, 2],
        ["iendo", -1, 2],
        ["ar", -1, 2],
        ["er", -1, 2],
        ["ir", -1, 2],
        ["as", -1, 2],
        ["abas", 39, 2],
        ["adas", 39, 2],
        ["idas", 39, 2],
        ["aras", 39, 2],
        ["ieras", 39, 2],
        ["\u{00ED}as", 39, 2],
        ["ar\u{00ED}as", 45, 2],
        ["er\u{00ED}as", 45, 2],
        ["ir\u{00ED}as", 45, 2],
        ["es", -1, 1],
        ["ases", 49, 2],
        ["ieses", 49, 2],
        ["abais", -1, 2],
        ["arais", -1, 2],
        ["ierais", -1, 2],
        ["\u{00ED}ais", -1, 2],
        ["ar\u{00ED}ais", 55, 2],
        ["er\u{00ED}ais", 55, 2],
        ["ir\u{00ED}ais", 55, 2],
        ["aseis", -1, 2],
        ["ieseis", -1, 2],
        ["asteis", -1, 2],
        ["isteis", -1, 2],
        ["\u{00E1}is", -1, 2],
        ["\u{00E9}is", -1, 1],
        ["ar\u{00E9}is", 64, 2],
        ["er\u{00E9}is", 64, 2],
        ["ir\u{00E9}is", 64, 2],
        ["ados", -1, 2],
        ["idos", -1, 2],
        ["amos", -1, 2],
        ["\u{00E1}bamos", 70, 2],
        ["\u{00E1}ramos", 70, 2],
        ["i\u{00E9}ramos", 70, 2],
        ["\u{00ED}amos", 70, 2],
        ["ar\u{00ED}amos", 74, 2],
        ["er\u{00ED}amos", 74, 2],
        ["ir\u{00ED}amos", 74, 2],
        ["emos", -1, 1],
        ["aremos", 78, 2],
        ["eremos", 78, 2],
        ["iremos", 78, 2],
        ["\u{00E1}semos", 78, 2],
        ["i\u{00E9}semos", 78, 2],
        ["imos", -1, 2],
        ["ar\u{00E1}s", -1, 2],
        ["er\u{00E1}s", -1, 2],
        ["ir\u{00E1}s", -1, 2],
        ["\u{00ED}s", -1, 2],
        ["ar\u{00E1}", -1, 2],
        ["er\u{00E1}", -1, 2],
        ["ir\u{00E1}", -1, 2],
        ["ar\u{00E9}", -1, 2],
        ["er\u{00E9}", -1, 2],
        ["ir\u{00E9}", -1, 2],
        ["i\u{00F3}", -1, 2]
    ];

    private const A_9 = [
        ["a", -1, 1],
        ["e", -1, 2],
        ["o", -1, 1],
        ["os", -1, 1],
        ["\u{00E1}", -1, 1],
        ["\u{00E9}", -1, 2],
        ["\u{00ED}", -1, 1],
        ["\u{00F3}", -1, 1]
    ];

    private const G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "\u{00E1}"=>true, "\u{00E9}"=>true, "\u{00ED}"=>true, "\u{00F3}"=>true, "\u{00FA}"=>true, "\u{00FC}"=>true];

    private int $I_p2 = 0;
    private int $I_p1 = 0;
    private int $I_pV = 0;



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
        if (!($this->out_grouping(self::G_v))) {
            goto lab0;
        }
        $v_4 = $this->cursor;
        if (!($this->out_grouping(self::G_v))) {
            goto lab5;
        }
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab5;
        }
        $this->inc_cursor();
        goto lab6;
    lab5:
        $this->cursor = $v_4;
        if (!($this->in_grouping(self::G_v))) {
            goto lab0;
        }
        if ($this->cursor >= $this->limit) {
            goto lab0;
        }
        $this->inc_cursor();
    lab6:
    lab4:
        $this->I_pV = $this->cursor;
    lab0:
        $this->cursor = $v_1;
        $v_5 = $this->cursor;
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab7;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab7;
        }
        $this->inc_cursor();
        $this->I_p1 = $this->cursor;
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab7;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab7;
        }
        $this->inc_cursor();
        $this->I_p2 = $this->cursor;
    lab7:
        $this->cursor = $v_5;
        return true;
    }


    protected function r_postlude(): bool
    {
        while (true) {
            $v_1 = $this->cursor;
            $this->bra = $this->cursor;
            $among_var = $this->find_among(self::A_0);
            $this->ket = $this->cursor;
            switch ($among_var) {
                case 1:
                    $this->slice_from("a");
                    break;
                case 2:
                    $this->slice_from("e");
                    break;
                case 3:
                    $this->slice_from("i");
                    break;
                case 4:
                    $this->slice_from("o");
                    break;
                case 5:
                    $this->slice_from("u");
                    break;
                case 6:
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
        if ($this->find_among_b(self::A_1) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        $among_var = $this->find_among_b(self::A_2);
        if (0 === $among_var) {
            return false;
        }
        if (!$this->r_RV()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->bra = $this->cursor;
                $this->slice_from("iendo");
                break;
            case 2:
                $this->bra = $this->cursor;
                $this->slice_from("ando");
                break;
            case 3:
                $this->bra = $this->cursor;
                $this->slice_from("ar");
                break;
            case 4:
                $this->bra = $this->cursor;
                $this->slice_from("er");
                break;
            case 5:
                $this->bra = $this->cursor;
                $this->slice_from("ir");
                break;
            case 6:
                $this->slice_del();
                break;
            case 7:
                if (!($this->eq_s_b("u"))) {
                    return false;
                }
                $this->slice_del();
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
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_del();
                $v_2 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                $among_var = $this->find_among_b(self::A_3);
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
            case 7:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                $v_3 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                if ($this->find_among_b(self::A_4) === 0) {
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
            case 8:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                $v_4 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                if ($this->find_among_b(self::A_5) === 0) {
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
            case 9:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                $v_5 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                if (!($this->eq_s_b("at"))) {
                    $this->cursor = $this->limit - $v_5;
                    goto lab4;
                }
                $this->bra = $this->cursor;
                if (!$this->r_R2()) {
                    $this->cursor = $this->limit - $v_5;
                    goto lab4;
                }
                $this->slice_del();
            lab4:
                break;
        }
        return true;
    }


    protected function r_y_verb_suffix(): bool
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
        $this->limit_backward = $v_1;
        if (!($this->eq_s_b("u"))) {
            return false;
        }
        $this->slice_del();
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
        $among_var = $this->find_among_b(self::A_8);
        if (0 === $among_var) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        switch ($among_var) {
            case 1:
                $v_2 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("u"))) {
                    $this->cursor = $this->limit - $v_2;
                    goto lab0;
                }
                $v_3 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("g"))) {
                    $this->cursor = $this->limit - $v_2;
                    goto lab0;
                }
                $this->cursor = $this->limit - $v_3;
            lab0:
                $this->bra = $this->cursor;
                $this->slice_del();
                break;
            case 2:
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_residual_suffix(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_9);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_RV()) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if (!$this->r_RV()) {
                    return false;
                }
                $this->slice_del();
                $v_1 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                if (!($this->eq_s_b("u"))) {
                    $this->cursor = $this->limit - $v_1;
                    goto lab0;
                }
                $this->bra = $this->cursor;
                $v_2 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("g"))) {
                    $this->cursor = $this->limit - $v_1;
                    goto lab0;
                }
                $this->cursor = $this->limit - $v_2;
                if (!$this->r_RV()) {
                    $this->cursor = $this->limit - $v_1;
                    goto lab0;
                }
                $this->slice_del();
            lab0:
                break;
        }
        return true;
    }


    public function stem(): bool
    {
        $this->r_mark_regions();
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_1 = $this->limit - $this->cursor;
        $this->r_attached_pronoun();
        $this->cursor = $this->limit - $v_1;
        $v_2 = $this->limit - $this->cursor;
        $v_3 = $this->limit - $this->cursor;
        if (!$this->r_standard_suffix()) {
            goto lab1;
        }
        goto lab2;
    lab1:
        $this->cursor = $this->limit - $v_3;
        if (!$this->r_y_verb_suffix()) {
            goto lab3;
        }
        goto lab2;
    lab3:
        $this->cursor = $this->limit - $v_3;
        if (!$this->r_verb_suffix()) {
            goto lab0;
        }
    lab2:
    lab0:
        $this->cursor = $this->limit - $v_2;
        $v_4 = $this->limit - $this->cursor;
        $this->r_residual_suffix();
        $this->cursor = $this->limit - $v_4;
        $this->cursor = $this->limit_backward;
        $v_5 = $this->cursor;
        $this->r_postlude();
        $this->cursor = $v_5;
        return true;
    }
}
