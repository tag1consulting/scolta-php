<?php

namespace Tag1\Scolta\Index\Snowball;
// Generated from romanian.sbl by Snowball 3.0.0 - https://snowballstem.org/

class RomanianStemmer extends SnowballStemmer
{
    private const A_0 = [
        ["\u{015F}", -1, 1],
        ["\u{0163}", -1, 2]
    ];

    private const A_1 = [
        ["", -1, 3],
        ["I", 0, 1],
        ["U", 0, 2]
    ];

    private const A_2 = [
        ["ea", -1, 3],
        ["a\u{021B}ia", -1, 7],
        ["aua", -1, 2],
        ["iua", -1, 4],
        ["a\u{021B}ie", -1, 7],
        ["ele", -1, 3],
        ["ile", -1, 5],
        ["iile", 6, 4],
        ["iei", -1, 4],
        ["atei", -1, 6],
        ["ii", -1, 4],
        ["ului", -1, 1],
        ["ul", -1, 1],
        ["elor", -1, 3],
        ["ilor", -1, 4],
        ["iilor", 14, 4]
    ];

    private const A_3 = [
        ["icala", -1, 4],
        ["iciva", -1, 4],
        ["ativa", -1, 5],
        ["itiva", -1, 6],
        ["icale", -1, 4],
        ["a\u{021B}iune", -1, 5],
        ["i\u{021B}iune", -1, 6],
        ["atoare", -1, 5],
        ["itoare", -1, 6],
        ["\u{0103}toare", -1, 5],
        ["icitate", -1, 4],
        ["abilitate", -1, 1],
        ["ibilitate", -1, 2],
        ["ivitate", -1, 3],
        ["icive", -1, 4],
        ["ative", -1, 5],
        ["itive", -1, 6],
        ["icali", -1, 4],
        ["atori", -1, 5],
        ["icatori", 18, 4],
        ["itori", -1, 6],
        ["\u{0103}tori", -1, 5],
        ["icitati", -1, 4],
        ["abilitati", -1, 1],
        ["ivitati", -1, 3],
        ["icivi", -1, 4],
        ["ativi", -1, 5],
        ["itivi", -1, 6],
        ["icit\u{0103}i", -1, 4],
        ["abilit\u{0103}i", -1, 1],
        ["ivit\u{0103}i", -1, 3],
        ["icit\u{0103}\u{021B}i", -1, 4],
        ["abilit\u{0103}\u{021B}i", -1, 1],
        ["ivit\u{0103}\u{021B}i", -1, 3],
        ["ical", -1, 4],
        ["ator", -1, 5],
        ["icator", 35, 4],
        ["itor", -1, 6],
        ["\u{0103}tor", -1, 5],
        ["iciv", -1, 4],
        ["ativ", -1, 5],
        ["itiv", -1, 6],
        ["ical\u{0103}", -1, 4],
        ["iciv\u{0103}", -1, 4],
        ["ativ\u{0103}", -1, 5],
        ["itiv\u{0103}", -1, 6]
    ];

    private const A_4 = [
        ["ica", -1, 1],
        ["abila", -1, 1],
        ["ibila", -1, 1],
        ["oasa", -1, 1],
        ["ata", -1, 1],
        ["ita", -1, 1],
        ["anta", -1, 1],
        ["ista", -1, 3],
        ["uta", -1, 1],
        ["iva", -1, 1],
        ["ic", -1, 1],
        ["ice", -1, 1],
        ["abile", -1, 1],
        ["ibile", -1, 1],
        ["isme", -1, 3],
        ["iune", -1, 2],
        ["oase", -1, 1],
        ["ate", -1, 1],
        ["itate", 17, 1],
        ["ite", -1, 1],
        ["ante", -1, 1],
        ["iste", -1, 3],
        ["ute", -1, 1],
        ["ive", -1, 1],
        ["ici", -1, 1],
        ["abili", -1, 1],
        ["ibili", -1, 1],
        ["iuni", -1, 2],
        ["atori", -1, 1],
        ["osi", -1, 1],
        ["ati", -1, 1],
        ["itati", 30, 1],
        ["iti", -1, 1],
        ["anti", -1, 1],
        ["isti", -1, 3],
        ["uti", -1, 1],
        ["i\u{0219}ti", -1, 3],
        ["ivi", -1, 1],
        ["it\u{0103}i", -1, 1],
        ["o\u{0219}i", -1, 1],
        ["it\u{0103}\u{021B}i", -1, 1],
        ["abil", -1, 1],
        ["ibil", -1, 1],
        ["ism", -1, 3],
        ["ator", -1, 1],
        ["os", -1, 1],
        ["at", -1, 1],
        ["it", -1, 1],
        ["ant", -1, 1],
        ["ist", -1, 3],
        ["ut", -1, 1],
        ["iv", -1, 1],
        ["ic\u{0103}", -1, 1],
        ["abil\u{0103}", -1, 1],
        ["ibil\u{0103}", -1, 1],
        ["oas\u{0103}", -1, 1],
        ["at\u{0103}", -1, 1],
        ["it\u{0103}", -1, 1],
        ["ant\u{0103}", -1, 1],
        ["ist\u{0103}", -1, 3],
        ["ut\u{0103}", -1, 1],
        ["iv\u{0103}", -1, 1]
    ];

    private const A_5 = [
        ["ea", -1, 1],
        ["ia", -1, 1],
        ["esc", -1, 1],
        ["\u{0103}sc", -1, 1],
        ["ind", -1, 1],
        ["\u{00E2}nd", -1, 1],
        ["are", -1, 1],
        ["ere", -1, 1],
        ["ire", -1, 1],
        ["\u{00E2}re", -1, 1],
        ["se", -1, 2],
        ["ase", 10, 1],
        ["sese", 10, 2],
        ["ise", 10, 1],
        ["use", 10, 1],
        ["\u{00E2}se", 10, 1],
        ["e\u{0219}te", -1, 1],
        ["\u{0103}\u{0219}te", -1, 1],
        ["eze", -1, 1],
        ["ai", -1, 1],
        ["eai", 19, 1],
        ["iai", 19, 1],
        ["sei", -1, 2],
        ["e\u{0219}ti", -1, 1],
        ["\u{0103}\u{0219}ti", -1, 1],
        ["ui", -1, 1],
        ["ezi", -1, 1],
        ["a\u{0219}i", -1, 1],
        ["se\u{0219}i", -1, 2],
        ["ase\u{0219}i", 28, 1],
        ["sese\u{0219}i", 28, 2],
        ["ise\u{0219}i", 28, 1],
        ["use\u{0219}i", 28, 1],
        ["\u{00E2}se\u{0219}i", 28, 1],
        ["i\u{0219}i", -1, 1],
        ["u\u{0219}i", -1, 1],
        ["\u{00E2}\u{0219}i", -1, 1],
        ["a\u{021B}i", -1, 2],
        ["ea\u{021B}i", 37, 1],
        ["ia\u{021B}i", 37, 1],
        ["e\u{021B}i", -1, 2],
        ["i\u{021B}i", -1, 2],
        ["ar\u{0103}\u{021B}i", -1, 1],
        ["ser\u{0103}\u{021B}i", -1, 2],
        ["aser\u{0103}\u{021B}i", 43, 1],
        ["seser\u{0103}\u{021B}i", 43, 2],
        ["iser\u{0103}\u{021B}i", 43, 1],
        ["user\u{0103}\u{021B}i", 43, 1],
        ["\u{00E2}ser\u{0103}\u{021B}i", 43, 1],
        ["ir\u{0103}\u{021B}i", -1, 1],
        ["ur\u{0103}\u{021B}i", -1, 1],
        ["\u{00E2}r\u{0103}\u{021B}i", -1, 1],
        ["\u{00E2}\u{021B}i", -1, 2],
        ["\u{00E2}i", -1, 1],
        ["am", -1, 1],
        ["eam", 54, 1],
        ["iam", 54, 1],
        ["em", -1, 2],
        ["asem", 57, 1],
        ["sesem", 57, 2],
        ["isem", 57, 1],
        ["usem", 57, 1],
        ["\u{00E2}sem", 57, 1],
        ["im", -1, 2],
        ["\u{0103}m", -1, 2],
        ["ar\u{0103}m", 64, 1],
        ["ser\u{0103}m", 64, 2],
        ["aser\u{0103}m", 66, 1],
        ["seser\u{0103}m", 66, 2],
        ["iser\u{0103}m", 66, 1],
        ["user\u{0103}m", 66, 1],
        ["\u{00E2}ser\u{0103}m", 66, 1],
        ["ir\u{0103}m", 64, 1],
        ["ur\u{0103}m", 64, 1],
        ["\u{00E2}r\u{0103}m", 64, 1],
        ["\u{00E2}m", -1, 2],
        ["au", -1, 1],
        ["eau", 76, 1],
        ["iau", 76, 1],
        ["indu", -1, 1],
        ["\u{00E2}ndu", -1, 1],
        ["ez", -1, 1],
        ["easc\u{0103}", -1, 1],
        ["ar\u{0103}", -1, 1],
        ["ser\u{0103}", -1, 2],
        ["aser\u{0103}", 84, 1],
        ["seser\u{0103}", 84, 2],
        ["iser\u{0103}", 84, 1],
        ["user\u{0103}", 84, 1],
        ["\u{00E2}ser\u{0103}", 84, 1],
        ["ir\u{0103}", -1, 1],
        ["ur\u{0103}", -1, 1],
        ["\u{00E2}r\u{0103}", -1, 1],
        ["eaz\u{0103}", -1, 1]
    ];

    private const A_6 = [
        ["a", -1, 1],
        ["e", -1, 1],
        ["ie", 1, 1],
        ["i", -1, 1],
        ["\u{0103}", -1, 1]
    ];

    private const G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "\u{00E2}"=>true, "\u{00EE}"=>true, "\u{0103}"=>true];

    private bool $B_standard_suffix_removed = false;
    private int $I_p2 = 0;
    private int $I_p1 = 0;
    private int $I_pV = 0;



    protected function r_norm(): bool
    {
        $v_1 = $this->cursor;
        while (true) {
            $v_2 = $this->cursor;
            while (true) {
                $v_3 = $this->cursor;
                $this->bra = $this->cursor;
                $among_var = $this->find_among(self::A_0);
                if (0 === $among_var) {
                    goto lab2;
                }
                $this->ket = $this->cursor;
                switch ($among_var) {
                    case 1:
                        $this->slice_from("\u{0219}");
                        break;
                    case 2:
                        $this->slice_from("\u{021B}");
                        break;
                }
                $this->cursor = $v_3;
                break;
            lab2:
                $this->cursor = $v_3;
                if ($this->cursor >= $this->limit) {
                    goto lab1;
                }
                $this->inc_cursor();
            }
            continue;
        lab1:
            $this->cursor = $v_2;
            break;
        }
    lab0:
        $this->cursor = $v_1;
        return true;
    }


    protected function r_prelude(): bool
    {
        while (true) {
            $v_1 = $this->cursor;
            while (true) {
                $v_2 = $this->cursor;
                if (!($this->in_grouping(self::G_v))) {
                    goto lab1;
                }
                $this->bra = $this->cursor;
                $v_3 = $this->cursor;
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
                $this->cursor = $v_3;
                if (!($this->eq_s("i"))) {
                    goto lab1;
                }
                $this->ket = $this->cursor;
                if (!($this->in_grouping(self::G_v))) {
                    goto lab1;
                }
                $this->slice_from("I");
            lab3:
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


    protected function r_step_0(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_2);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $this->slice_from("a");
                break;
            case 3:
                $this->slice_from("e");
                break;
            case 4:
                $this->slice_from("i");
                break;
            case 5:
                $v_1 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("ab"))) {
                    goto lab0;
                }
                return false;
            lab0:
                $this->cursor = $this->limit - $v_1;
                $this->slice_from("i");
                break;
            case 6:
                $this->slice_from("at");
                break;
            case 7:
                $this->slice_from("a\u{021B}i");
                break;
        }
        return true;
    }


    protected function r_combo_suffix(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_3);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_from("abil");
                break;
            case 2:
                $this->slice_from("ibil");
                break;
            case 3:
                $this->slice_from("iv");
                break;
            case 4:
                $this->slice_from("ic");
                break;
            case 5:
                $this->slice_from("at");
                break;
            case 6:
                $this->slice_from("it");
                break;
        }
        $this->B_standard_suffix_removed = true;
        $this->cursor = $this->limit - $v_1;
        return true;
    }


    protected function r_standard_suffix(): bool
    {
        $this->B_standard_suffix_removed = false;
        while (true) {
            $v_1 = $this->limit - $this->cursor;
            if (!$this->r_combo_suffix()) {
                goto lab0;
            }
            continue;
        lab0:
            $this->cursor = $this->limit - $v_1;
            break;
        }
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_4);
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
                if (!($this->eq_s_b("\u{021B}"))) {
                    return false;
                }
                $this->bra = $this->cursor;
                $this->slice_from("t");
                break;
            case 3:
                $this->slice_from("ist");
                break;
        }
        $this->B_standard_suffix_removed = true;
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
        $among_var = $this->find_among_b(self::A_5);
        if (0 === $among_var) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $v_2 = $this->limit - $this->cursor;
                if (!($this->out_grouping_b(self::G_v))) {
                    goto lab0;
                }
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_2;
                if (!($this->eq_s_b("u"))) {
                    $this->limit_backward = $v_1;
                    return false;
                }
            lab1:
                $this->slice_del();
                break;
            case 2:
                $this->slice_del();
                break;
        }
        $this->limit_backward = $v_1;
        return true;
    }


    protected function r_vowel_suffix(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_6) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_RV()) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    public function stem(): bool
    {
        $this->r_norm();
        $v_1 = $this->cursor;
        $this->r_prelude();
        $this->cursor = $v_1;
        $this->r_mark_regions();
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_2 = $this->limit - $this->cursor;
        $this->r_step_0();
        $this->cursor = $this->limit - $v_2;
        $v_3 = $this->limit - $this->cursor;
        $this->r_standard_suffix();
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        $v_5 = $this->limit - $this->cursor;
        if (!$this->B_standard_suffix_removed) {
            goto lab1;
        }
        goto lab2;
    lab1:
        $this->cursor = $this->limit - $v_5;
        if (!$this->r_verb_suffix()) {
            goto lab0;
        }
    lab2:
    lab0:
        $this->cursor = $this->limit - $v_4;
        $v_6 = $this->limit - $this->cursor;
        $this->r_vowel_suffix();
        $this->cursor = $this->limit - $v_6;
        $this->cursor = $this->limit_backward;
        $v_7 = $this->cursor;
        $this->r_postlude();
        $this->cursor = $v_7;
        return true;
    }
}
