<?php

namespace Tag1\Scolta\Index\Snowball;
// Generated from catalan.sbl by Snowball 3.0.0 - https://snowballstem.org/

class CatalanStemmer extends SnowballStemmer
{
    private const A_0 = [
        ["", -1, 7],
        ["\u{00B7}", 0, 6],
        ["\u{00E0}", 0, 1],
        ["\u{00E1}", 0, 1],
        ["\u{00E8}", 0, 2],
        ["\u{00E9}", 0, 2],
        ["\u{00EC}", 0, 3],
        ["\u{00ED}", 0, 3],
        ["\u{00EF}", 0, 3],
        ["\u{00F2}", 0, 4],
        ["\u{00F3}", 0, 4],
        ["\u{00FA}", 0, 5],
        ["\u{00FC}", 0, 5]
    ];

    private const A_1 = [
        ["la", -1, 1],
        ["-la", 0, 1],
        ["sela", 0, 1],
        ["le", -1, 1],
        ["me", -1, 1],
        ["-me", 4, 1],
        ["se", -1, 1],
        ["-te", -1, 1],
        ["hi", -1, 1],
        ["'hi", 8, 1],
        ["li", -1, 1],
        ["-li", 10, 1],
        ["'l", -1, 1],
        ["'m", -1, 1],
        ["-m", -1, 1],
        ["'n", -1, 1],
        ["-n", -1, 1],
        ["ho", -1, 1],
        ["'ho", 17, 1],
        ["lo", -1, 1],
        ["selo", 19, 1],
        ["'s", -1, 1],
        ["las", -1, 1],
        ["selas", 22, 1],
        ["les", -1, 1],
        ["-les", 24, 1],
        ["'ls", -1, 1],
        ["-ls", -1, 1],
        ["'ns", -1, 1],
        ["-ns", -1, 1],
        ["ens", -1, 1],
        ["los", -1, 1],
        ["selos", 31, 1],
        ["nos", -1, 1],
        ["-nos", 33, 1],
        ["vos", -1, 1],
        ["us", -1, 1],
        ["-us", 36, 1],
        ["'t", -1, 1]
    ];

    private const A_2 = [
        ["ica", -1, 4],
        ["l\u{00F3}gica", 0, 3],
        ["enca", -1, 1],
        ["ada", -1, 2],
        ["ancia", -1, 1],
        ["encia", -1, 1],
        ["\u{00E8}ncia", -1, 1],
        ["\u{00ED}cia", -1, 1],
        ["logia", -1, 3],
        ["inia", -1, 1],
        ["\u{00ED}inia", 9, 1],
        ["eria", -1, 1],
        ["\u{00E0}ria", -1, 1],
        ["at\u{00F2}ria", -1, 1],
        ["alla", -1, 1],
        ["ella", -1, 1],
        ["\u{00ED}vola", -1, 1],
        ["ima", -1, 1],
        ["\u{00ED}ssima", 17, 1],
        ["qu\u{00ED}ssima", 18, 5],
        ["ana", -1, 1],
        ["ina", -1, 1],
        ["era", -1, 1],
        ["sfera", 22, 1],
        ["ora", -1, 1],
        ["dora", 24, 1],
        ["adora", 25, 1],
        ["adura", -1, 1],
        ["esa", -1, 1],
        ["osa", -1, 1],
        ["assa", -1, 1],
        ["essa", -1, 1],
        ["issa", -1, 1],
        ["eta", -1, 1],
        ["ita", -1, 1],
        ["ota", -1, 1],
        ["ista", -1, 1],
        ["ialista", 36, 1],
        ["ionista", 36, 1],
        ["iva", -1, 1],
        ["ativa", 39, 1],
        ["n\u{00E7}a", -1, 1],
        ["log\u{00ED}a", -1, 3],
        ["ic", -1, 4],
        ["\u{00ED}stic", 43, 1],
        ["enc", -1, 1],
        ["esc", -1, 1],
        ["ud", -1, 1],
        ["atge", -1, 1],
        ["ble", -1, 1],
        ["able", 49, 1],
        ["ible", 49, 1],
        ["isme", -1, 1],
        ["ialisme", 52, 1],
        ["ionisme", 52, 1],
        ["ivisme", 52, 1],
        ["aire", -1, 1],
        ["icte", -1, 1],
        ["iste", -1, 1],
        ["ici", -1, 1],
        ["\u{00ED}ci", -1, 1],
        ["logi", -1, 3],
        ["ari", -1, 1],
        ["tori", -1, 1],
        ["al", -1, 1],
        ["il", -1, 1],
        ["all", -1, 1],
        ["ell", -1, 1],
        ["\u{00ED}vol", -1, 1],
        ["isam", -1, 1],
        ["issem", -1, 1],
        ["\u{00EC}ssem", -1, 1],
        ["\u{00ED}ssem", -1, 1],
        ["\u{00ED}ssim", -1, 1],
        ["qu\u{00ED}ssim", 73, 5],
        ["amen", -1, 1],
        ["\u{00EC}ssin", -1, 1],
        ["ar", -1, 1],
        ["ificar", 77, 1],
        ["egar", 77, 1],
        ["ejar", 77, 1],
        ["itar", 77, 1],
        ["itzar", 77, 1],
        ["fer", -1, 1],
        ["or", -1, 1],
        ["dor", 84, 1],
        ["dur", -1, 1],
        ["doras", -1, 1],
        ["ics", -1, 4],
        ["l\u{00F3}gics", 88, 3],
        ["uds", -1, 1],
        ["nces", -1, 1],
        ["ades", -1, 2],
        ["ancies", -1, 1],
        ["encies", -1, 1],
        ["\u{00E8}ncies", -1, 1],
        ["\u{00ED}cies", -1, 1],
        ["logies", -1, 3],
        ["inies", -1, 1],
        ["\u{00ED}nies", -1, 1],
        ["eries", -1, 1],
        ["\u{00E0}ries", -1, 1],
        ["at\u{00F2}ries", -1, 1],
        ["bles", -1, 1],
        ["ables", 103, 1],
        ["ibles", 103, 1],
        ["imes", -1, 1],
        ["\u{00ED}ssimes", 106, 1],
        ["qu\u{00ED}ssimes", 107, 5],
        ["formes", -1, 1],
        ["ismes", -1, 1],
        ["ialismes", 110, 1],
        ["ines", -1, 1],
        ["eres", -1, 1],
        ["ores", -1, 1],
        ["dores", 114, 1],
        ["idores", 115, 1],
        ["dures", -1, 1],
        ["eses", -1, 1],
        ["oses", -1, 1],
        ["asses", -1, 1],
        ["ictes", -1, 1],
        ["ites", -1, 1],
        ["otes", -1, 1],
        ["istes", -1, 1],
        ["ialistes", 124, 1],
        ["ionistes", 124, 1],
        ["iques", -1, 4],
        ["l\u{00F3}giques", 127, 3],
        ["ives", -1, 1],
        ["atives", 129, 1],
        ["log\u{00ED}es", -1, 3],
        ["alleng\u{00FC}es", -1, 1],
        ["icis", -1, 1],
        ["\u{00ED}cis", -1, 1],
        ["logis", -1, 3],
        ["aris", -1, 1],
        ["toris", -1, 1],
        ["ls", -1, 1],
        ["als", 138, 1],
        ["ells", 138, 1],
        ["ims", -1, 1],
        ["\u{00ED}ssims", 141, 1],
        ["qu\u{00ED}ssims", 142, 5],
        ["ions", -1, 1],
        ["cions", 144, 1],
        ["acions", 145, 2],
        ["esos", -1, 1],
        ["osos", -1, 1],
        ["assos", -1, 1],
        ["issos", -1, 1],
        ["ers", -1, 1],
        ["ors", -1, 1],
        ["dors", 152, 1],
        ["adors", 153, 1],
        ["idors", 153, 1],
        ["ats", -1, 1],
        ["itats", 156, 1],
        ["bilitats", 157, 1],
        ["ivitats", 157, 1],
        ["ativitats", 159, 1],
        ["\u{00EF}tats", 156, 1],
        ["ets", -1, 1],
        ["ants", -1, 1],
        ["ents", -1, 1],
        ["ments", 164, 1],
        ["aments", 165, 1],
        ["ots", -1, 1],
        ["uts", -1, 1],
        ["ius", -1, 1],
        ["trius", 169, 1],
        ["atius", 169, 1],
        ["\u{00E8}s", -1, 1],
        ["\u{00E9}s", -1, 1],
        ["\u{00ED}s", -1, 1],
        ["d\u{00ED}s", 174, 1],
        ["\u{00F3}s", -1, 1],
        ["itat", -1, 1],
        ["bilitat", 177, 1],
        ["ivitat", 177, 1],
        ["ativitat", 179, 1],
        ["\u{00EF}tat", -1, 1],
        ["et", -1, 1],
        ["ant", -1, 1],
        ["ent", -1, 1],
        ["ient", 184, 1],
        ["ment", 184, 1],
        ["ament", 186, 1],
        ["isament", 187, 1],
        ["ot", -1, 1],
        ["isseu", -1, 1],
        ["\u{00EC}sseu", -1, 1],
        ["\u{00ED}sseu", -1, 1],
        ["triu", -1, 1],
        ["\u{00ED}ssiu", -1, 1],
        ["atiu", -1, 1],
        ["\u{00F3}", -1, 1],
        ["i\u{00F3}", 196, 1],
        ["ci\u{00F3}", 197, 1],
        ["aci\u{00F3}", 198, 1]
    ];

    private const A_3 = [
        ["aba", -1, 1],
        ["esca", -1, 1],
        ["isca", -1, 1],
        ["\u{00EF}sca", -1, 1],
        ["ada", -1, 1],
        ["ida", -1, 1],
        ["uda", -1, 1],
        ["\u{00EF}da", -1, 1],
        ["ia", -1, 1],
        ["aria", 8, 1],
        ["iria", 8, 1],
        ["ara", -1, 1],
        ["iera", -1, 1],
        ["ira", -1, 1],
        ["adora", -1, 1],
        ["\u{00EF}ra", -1, 1],
        ["ava", -1, 1],
        ["ixa", -1, 1],
        ["itza", -1, 1],
        ["\u{00ED}a", -1, 1],
        ["ar\u{00ED}a", 19, 1],
        ["er\u{00ED}a", 19, 1],
        ["ir\u{00ED}a", 19, 1],
        ["\u{00EF}a", -1, 1],
        ["isc", -1, 1],
        ["\u{00EF}sc", -1, 1],
        ["ad", -1, 1],
        ["ed", -1, 1],
        ["id", -1, 1],
        ["ie", -1, 1],
        ["re", -1, 1],
        ["dre", 30, 1],
        ["ase", -1, 1],
        ["iese", -1, 1],
        ["aste", -1, 1],
        ["iste", -1, 1],
        ["ii", -1, 1],
        ["ini", -1, 1],
        ["esqui", -1, 1],
        ["eixi", -1, 1],
        ["itzi", -1, 1],
        ["am", -1, 1],
        ["em", -1, 1],
        ["arem", 42, 1],
        ["irem", 42, 1],
        ["\u{00E0}rem", 42, 1],
        ["\u{00ED}rem", 42, 1],
        ["\u{00E0}ssem", 42, 1],
        ["\u{00E9}ssem", 42, 1],
        ["iguem", 42, 1],
        ["\u{00EF}guem", 42, 1],
        ["avem", 42, 1],
        ["\u{00E0}vem", 42, 1],
        ["\u{00E1}vem", 42, 1],
        ["ir\u{00EC}em", 42, 1],
        ["\u{00ED}em", 42, 1],
        ["ar\u{00ED}em", 55, 1],
        ["ir\u{00ED}em", 55, 1],
        ["assim", -1, 1],
        ["essim", -1, 1],
        ["issim", -1, 1],
        ["\u{00E0}ssim", -1, 1],
        ["\u{00E8}ssim", -1, 1],
        ["\u{00E9}ssim", -1, 1],
        ["\u{00ED}ssim", -1, 1],
        ["\u{00EF}m", -1, 1],
        ["an", -1, 1],
        ["aban", 66, 1],
        ["arian", 66, 1],
        ["aran", 66, 1],
        ["ieran", 66, 1],
        ["iran", 66, 1],
        ["\u{00ED}an", 66, 1],
        ["ar\u{00ED}an", 72, 1],
        ["er\u{00ED}an", 72, 1],
        ["ir\u{00ED}an", 72, 1],
        ["en", -1, 1],
        ["ien", 76, 1],
        ["arien", 77, 1],
        ["irien", 77, 1],
        ["aren", 76, 1],
        ["eren", 76, 1],
        ["iren", 76, 1],
        ["\u{00E0}ren", 76, 1],
        ["\u{00EF}ren", 76, 1],
        ["asen", 76, 1],
        ["iesen", 76, 1],
        ["assen", 76, 1],
        ["essen", 76, 1],
        ["issen", 76, 1],
        ["\u{00E9}ssen", 76, 1],
        ["\u{00EF}ssen", 76, 1],
        ["esquen", 76, 1],
        ["isquen", 76, 1],
        ["\u{00EF}squen", 76, 1],
        ["aven", 76, 1],
        ["ixen", 76, 1],
        ["eixen", 96, 1],
        ["\u{00EF}xen", 76, 1],
        ["\u{00EF}en", 76, 1],
        ["in", -1, 1],
        ["inin", 100, 1],
        ["sin", 100, 1],
        ["isin", 102, 1],
        ["assin", 102, 1],
        ["essin", 102, 1],
        ["issin", 102, 1],
        ["\u{00EF}ssin", 102, 1],
        ["esquin", 100, 1],
        ["eixin", 100, 1],
        ["aron", -1, 1],
        ["ieron", -1, 1],
        ["ar\u{00E1}n", -1, 1],
        ["er\u{00E1}n", -1, 1],
        ["ir\u{00E1}n", -1, 1],
        ["i\u{00EF}n", -1, 1],
        ["ado", -1, 1],
        ["ido", -1, 1],
        ["ando", -1, 2],
        ["iendo", -1, 1],
        ["io", -1, 1],
        ["ixo", -1, 1],
        ["eixo", 121, 1],
        ["\u{00EF}xo", -1, 1],
        ["itzo", -1, 1],
        ["ar", -1, 1],
        ["tzar", 125, 1],
        ["er", -1, 1],
        ["eixer", 127, 1],
        ["ir", -1, 1],
        ["ador", -1, 1],
        ["as", -1, 1],
        ["abas", 131, 1],
        ["adas", 131, 1],
        ["idas", 131, 1],
        ["aras", 131, 1],
        ["ieras", 131, 1],
        ["\u{00ED}as", 131, 1],
        ["ar\u{00ED}as", 137, 1],
        ["er\u{00ED}as", 137, 1],
        ["ir\u{00ED}as", 137, 1],
        ["ids", -1, 1],
        ["es", -1, 1],
        ["ades", 142, 1],
        ["ides", 142, 1],
        ["udes", 142, 1],
        ["\u{00EF}des", 142, 1],
        ["atges", 142, 1],
        ["ies", 142, 1],
        ["aries", 148, 1],
        ["iries", 148, 1],
        ["ares", 142, 1],
        ["ires", 142, 1],
        ["adores", 142, 1],
        ["\u{00EF}res", 142, 1],
        ["ases", 142, 1],
        ["ieses", 142, 1],
        ["asses", 142, 1],
        ["esses", 142, 1],
        ["isses", 142, 1],
        ["\u{00EF}sses", 142, 1],
        ["ques", 142, 1],
        ["esques", 161, 1],
        ["\u{00EF}sques", 161, 1],
        ["aves", 142, 1],
        ["ixes", 142, 1],
        ["eixes", 165, 1],
        ["\u{00EF}xes", 142, 1],
        ["\u{00EF}es", 142, 1],
        ["abais", -1, 1],
        ["arais", -1, 1],
        ["ierais", -1, 1],
        ["\u{00ED}ais", -1, 1],
        ["ar\u{00ED}ais", 172, 1],
        ["er\u{00ED}ais", 172, 1],
        ["ir\u{00ED}ais", 172, 1],
        ["aseis", -1, 1],
        ["ieseis", -1, 1],
        ["asteis", -1, 1],
        ["isteis", -1, 1],
        ["inis", -1, 1],
        ["sis", -1, 1],
        ["isis", 181, 1],
        ["assis", 181, 1],
        ["essis", 181, 1],
        ["issis", 181, 1],
        ["\u{00EF}ssis", 181, 1],
        ["esquis", -1, 1],
        ["eixis", -1, 1],
        ["itzis", -1, 1],
        ["\u{00E1}is", -1, 1],
        ["ar\u{00E9}is", -1, 1],
        ["er\u{00E9}is", -1, 1],
        ["ir\u{00E9}is", -1, 1],
        ["ams", -1, 1],
        ["ados", -1, 1],
        ["idos", -1, 1],
        ["amos", -1, 1],
        ["\u{00E1}bamos", 197, 1],
        ["\u{00E1}ramos", 197, 1],
        ["i\u{00E9}ramos", 197, 1],
        ["\u{00ED}amos", 197, 1],
        ["ar\u{00ED}amos", 201, 1],
        ["er\u{00ED}amos", 201, 1],
        ["ir\u{00ED}amos", 201, 1],
        ["aremos", -1, 1],
        ["eremos", -1, 1],
        ["iremos", -1, 1],
        ["\u{00E1}semos", -1, 1],
        ["i\u{00E9}semos", -1, 1],
        ["imos", -1, 1],
        ["adors", -1, 1],
        ["ass", -1, 1],
        ["erass", 212, 1],
        ["ess", -1, 1],
        ["ats", -1, 1],
        ["its", -1, 1],
        ["ents", -1, 1],
        ["\u{00E0}s", -1, 1],
        ["ar\u{00E0}s", 218, 1],
        ["ir\u{00E0}s", 218, 1],
        ["ar\u{00E1}s", -1, 1],
        ["er\u{00E1}s", -1, 1],
        ["ir\u{00E1}s", -1, 1],
        ["\u{00E9}s", -1, 1],
        ["ar\u{00E9}s", 224, 1],
        ["\u{00ED}s", -1, 1],
        ["i\u{00EF}s", -1, 1],
        ["at", -1, 1],
        ["it", -1, 1],
        ["ant", -1, 1],
        ["ent", -1, 1],
        ["int", -1, 1],
        ["ut", -1, 1],
        ["\u{00EF}t", -1, 1],
        ["au", -1, 1],
        ["erau", 235, 1],
        ["ieu", -1, 1],
        ["ineu", -1, 1],
        ["areu", -1, 1],
        ["ireu", -1, 1],
        ["\u{00E0}reu", -1, 1],
        ["\u{00ED}reu", -1, 1],
        ["asseu", -1, 1],
        ["esseu", -1, 1],
        ["eresseu", 244, 1],
        ["\u{00E0}sseu", -1, 1],
        ["\u{00E9}sseu", -1, 1],
        ["igueu", -1, 1],
        ["\u{00EF}gueu", -1, 1],
        ["\u{00E0}veu", -1, 1],
        ["\u{00E1}veu", -1, 1],
        ["itzeu", -1, 1],
        ["\u{00EC}eu", -1, 1],
        ["ir\u{00EC}eu", 253, 1],
        ["\u{00ED}eu", -1, 1],
        ["ar\u{00ED}eu", 255, 1],
        ["ir\u{00ED}eu", 255, 1],
        ["assiu", -1, 1],
        ["issiu", -1, 1],
        ["\u{00E0}ssiu", -1, 1],
        ["\u{00E8}ssiu", -1, 1],
        ["\u{00E9}ssiu", -1, 1],
        ["\u{00ED}ssiu", -1, 1],
        ["\u{00EF}u", -1, 1],
        ["ix", -1, 1],
        ["eix", 265, 1],
        ["\u{00EF}x", -1, 1],
        ["itz", -1, 1],
        ["i\u{00E0}", -1, 1],
        ["ar\u{00E0}", -1, 1],
        ["ir\u{00E0}", -1, 1],
        ["itz\u{00E0}", -1, 1],
        ["ar\u{00E1}", -1, 1],
        ["er\u{00E1}", -1, 1],
        ["ir\u{00E1}", -1, 1],
        ["ir\u{00E8}", -1, 1],
        ["ar\u{00E9}", -1, 1],
        ["er\u{00E9}", -1, 1],
        ["ir\u{00E9}", -1, 1],
        ["\u{00ED}", -1, 1],
        ["i\u{00EF}", -1, 1],
        ["i\u{00F3}", -1, 1]
    ];

    private const A_4 = [
        ["a", -1, 1],
        ["e", -1, 1],
        ["i", -1, 1],
        ["\u{00EF}n", -1, 1],
        ["o", -1, 1],
        ["ir", -1, 1],
        ["s", -1, 1],
        ["is", 6, 1],
        ["os", 6, 1],
        ["\u{00EF}s", 6, 1],
        ["it", -1, 1],
        ["eu", -1, 1],
        ["iu", -1, 1],
        ["iqu", -1, 2],
        ["itz", -1, 1],
        ["\u{00E0}", -1, 1],
        ["\u{00E1}", -1, 1],
        ["\u{00E9}", -1, 1],
        ["\u{00EC}", -1, 1],
        ["\u{00ED}", -1, 1],
        ["\u{00EF}", -1, 1],
        ["\u{00F3}", -1, 1]
    ];

    private const G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "\u{00E0}"=>true, "\u{00E1}"=>true, "\u{00E8}"=>true, "\u{00E9}"=>true, "\u{00ED}"=>true, "\u{00EF}"=>true, "\u{00F2}"=>true, "\u{00F3}"=>true, "\u{00FA}"=>true, "\u{00FC}"=>true];

    private int $I_p2 = 0;
    private int $I_p1 = 0;



    protected function r_mark_regions(): bool
    {
        $this->I_p1 = $this->limit;
        $this->I_p2 = $this->limit;
        $v_1 = $this->cursor;
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
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


    protected function r_cleaning(): bool
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
                    $this->slice_from(".");
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
        if (!$this->r_R1()) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_standard_suffix(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_2);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
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
                $this->slice_from("ic");
                break;
            case 5:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_from("c");
                break;
        }
        return true;
    }


    protected function r_verb_suffix(): bool
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
                $this->slice_del();
                break;
            case 2:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_residual_suffix(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_4);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_from("ic");
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
        $this->r_cleaning();
        $this->cursor = $v_5;
        return true;
    }
}
