<?php

declare(strict_types=1);

/**
 * Generate the multilingual concordance test corpus.
 *
 * Run: php tests/fixtures/concordance/corpus-ml/generate.php
 * Produces 95 HTML files (19 languages × 5 pages) in the same directory.
 *
 * Languages: ar, zh, da, nl, en, fi, fr, de, hu, it, ja, ko, no, pt, ro, ru, es, sv, tr
 */

$dir = __DIR__;

// Each language has 5 pages: [title, body, date, filter-language]
$corpus = [
    // Arabic - technology theme
    'ar' => [
        'ar-01' => [
            'title' => 'مقدمة إلى الذكاء الاصطناعي',
            'body' => 'الذكاء الاصطناعي هو أحد أبرز مجالات علم الحاسوب الحديث. يشمل هذا المجال تطوير أنظمة قادرة على محاكاة القدرات الفكرية البشرية مثل التعلم والتفكير والفهم. تُستخدم تقنيات الذكاء الاصطناعي في مجالات متعددة كالطب والهندسة والتعليم والتجارة الإلكترونية. يعتمد التعلم الآلي على تحليل كميات ضخمة من البيانات للكشف عن أنماط مخفية. تساهم الشبكات العصبية الاصطناعية في تحسين دقة التنبؤات والتصنيفات. أصبحت معالجة اللغات الطبيعية جزءاً أساسياً من تطبيقات الذكاء الاصطناعي الحديثة.',
            'date' => '2026-04-01',
        ],
        'ar-02' => [
            'title' => 'تطور تقنيات البحث على الإنترنت',
            'body' => 'شهدت محركات البحث تطوراً هائلاً منذ ظهورها في التسعينيات. بدأت بأساليب بسيطة لفهرسة الصفحات ثم تطورت لتشمل خوارزميات معقدة. تعتمد محركات البحث الحديثة على تحليل النصوص والروابط والسياق. يساعد الفهرس المعكوس في تسريع عمليات البحث وإيجاد النتائج ذات الصلة. تُعدّ خوارزمية الترتيب من أهم مكونات محرك البحث لأنها تحدد أهمية كل صفحة. يتطلب إنشاء محرك بحث فعّال معالجة ملايين الصفحات يومياً وتحديث الفهارس باستمرار.',
            'date' => '2026-04-02',
        ],
        'ar-03' => [
            'title' => 'البرمجة والخوارزميات',
            'body' => 'الخوارزمية هي مجموعة من الخطوات المحددة لحل مشكلة ما. تُصنّف الخوارزميات بناءً على تعقيدها الزمني والمكاني. تشمل الخوارزميات الشائعة البحث الثنائي والفرز السريع وخوارزميات المخططات. يُعدّ تحليل التعقيد الحسابي أساساً لقياس كفاءة الخوارزميات. تنقسم هياكل البيانات إلى مصفوفات وقوائم مرتبطة وأشجار ورسوم بيانية. يُساعد تحسين الخوارزميات في توفير موارد الحاسوب وتسريع تنفيذ البرامج بشكل ملحوظ.',
            'date' => '2026-04-03',
        ],
        'ar-04' => [
            'title' => 'قواعد البيانات وإدارة المعلومات',
            'body' => 'قاعدة البيانات هي مجموعة منظمة من المعلومات المخزنة إلكترونياً. تنقسم قواعد البيانات إلى نوعين رئيسيين: العلائقية وغير العلائقية. تستخدم قواعد البيانات العلائقية لغة الاستعلام المهيكلة للوصول إلى البيانات. تتميز قواعد البيانات غير العلائقية بمرونتها في تخزين البيانات غير المنظمة. يُعدّ النسخ الاحتياطي وضمان الأمان من أهم مهام مدير قاعدة البيانات. تُستخدم قواعد البيانات في كل التطبيقات الحديثة من المواقع إلى تطبيقات الهاتف المحمول.',
            'date' => '2026-04-04',
        ],
        'ar-05' => [
            'title' => 'أمن المعلومات والخصوصية',
            'body' => 'أمن المعلومات هو ممارسة حماية البيانات الرقمية من الوصول غير المصرح به. يشمل التشفير والمصادقة والتحكم في الوصول. تُعدّ الهجمات الإلكترونية تهديداً متزايداً للمؤسسات والأفراد. يحتاج المتخصصون في أمن المعلومات إلى معرفة واسعة بأساليب الاختراق والدفاع. تلعب سياسات الخصوصية دوراً محورياً في حماية بيانات المستخدمين. يُوصى بتحديث كلمات المرور بانتظام واستخدام المصادقة الثنائية لحماية الحسابات الرقمية.',
            'date' => '2026-04-05',
        ],
    ],

    // Chinese Simplified - culture/history theme
    'zh' => [
        'zh-01' => [
            'title' => '中国传统文化概述',
            'body' => '中国传统文化博大精深，历史悠久。儒家思想强调仁义礼智信，是中国文化的核心价值观之一。道家追求自然和谐，主张无为而治的处世哲学。佛教自汉代传入中国后与本土文化融合，形成了独特的中国佛教流派。中国传统节日如春节、中秋节和端午节承载了丰富的文化内涵。诗词书画是中国传统艺术的精华，唐诗宋词至今仍被广泛传诵。中国传统医学强调阴阳平衡和整体观念，是世界医学宝库的重要组成部分。',
            'date' => '2026-04-01',
        ],
        'zh-02' => [
            'title' => '中国历史的重要朝代',
            'body' => '中国历史跨越五千年，经历了众多朝代的兴衰更替。秦朝是中国历史上第一个统一的封建王朝，秦始皇建立了中央集权制度。汉朝开创了汉文化的黄金时代，丝绸之路的建立促进了东西方文明交流。唐朝是中国历史上最为繁荣昌盛的时代之一，长安是当时世界上最大的城市。宋朝在经济和文化方面取得了巨大成就，发明了活字印刷术和指南针。明清两朝建立了完善的官僚制度，故宫是这一时期建筑艺术的杰出代表。',
            'date' => '2026-04-02',
        ],
        'zh-03' => [
            'title' => '中国饮食文化',
            'body' => '中国饮食文化丰富多彩，各地菜系各有特色。川菜以麻辣著称，使用花椒和辣椒创造出独特的麻辣风味。粤菜注重食材的新鲜和原汁原味，清蒸和白灼是常见的烹饪方法。北京烤鸭是中国最具代表性的美食之一，皮脆肉嫩。饺子作为北方传统食物，在春节期间尤为重要，象征团圆和好运。中国茶文化源远流长，绿茶、红茶、乌龙茶等各有其独特的品茗文化。中餐的烹饪技巧包括炒、炖、蒸、烤等多种方法，体现了饮食艺术的精湛。',
            'date' => '2026-04-03',
        ],
        'zh-04' => [
            'title' => '现代中国的科技发展',
            'body' => '中国在科技领域取得了举世瞩目的成就。高铁网络的快速扩展使中国拥有了世界上最大的高速铁路系统。移动支付技术的普及改变了人们的日常消费方式，微信支付和支付宝成为主流支付手段。人工智能研究在中国蓬勃发展，在图像识别和自然语言处理领域处于世界前列。中国的航天事业不断取得突破，探月工程和空间站建设彰显了强大的科技实力。5G网络的大规模部署为数字经济发展提供了强大的基础设施支撑。',
            'date' => '2026-04-04',
        ],
        'zh-05' => [
            'title' => '中国的自然风光与地理',
            'body' => '中国地大物博，自然风光壮丽多样。长江是亚洲最长的河流，孕育了灿烂的中华文明。黄山以其奇松怪石云海温泉而闻名于世，是中国最美的山岳之一。西藏高原被誉为世界屋脊，拥有独特的高原生态系统和藏族文化。云南省生物多样性极为丰富，被誉为植物王国和动物王国。桂林山水以其独特的喀斯特地貌和漓江风光吸引了世界各地的游客。北京是中国的首都，拥有长城和颐和园等众多世界文化遗产。',
            'date' => '2026-04-05',
        ],
    ],

    // Danish - nature/environment theme
    'da' => [
        'da-01' => [
            'title' => 'Danmarks natur og miljø',
            'body' => 'Danmark er et lille land med en rig og varieret natur. Landet er omgivet af hav på næsten alle sider, og kystlinjen strækker sig over tusinder af kilometer. De danske skove er hjemsted for mange forskellige dyre- og plantearter, herunder råvildt, ræve og talrige fuglearter. Vadehavet er et unikt naturområde og UNESCO-verdensarvssted, der hvert år besøges af millioner af trækfugle. Klimaet i Danmark er tempereret med milde somre og kolde vintre, hvilket giver ideelle betingelser for et bredt udvalg af vegetation. Danskerne er generelt meget naturbevidste og lægger stor vægt på bæredygtighed og miljøbeskyttelse.',
            'date' => '2026-04-01',
        ],
        'da-02' => [
            'title' => 'Vedvarende energi i Danmark',
            'body' => 'Danmark er et af verdens førende lande inden for vedvarende energi, særligt vindenergi. Vindmøller er et velkendt syn i det danske landskab og til havs, og Danmark eksporterer vindenergi og vindteknologi til hele verden. Landet har en ambitiøs klimapolitik og sigter mod at blive klimaneutralt inden 2050. Solenergi vinder også frem som supplement til vindenergi, og mange danske husstande har solpaneler på taget. Den grønne omstilling kræver massive investeringer i infrastruktur og nye teknologier. Danskerne bakker generelt op om den grønne omstilling og er villige til at betale ekstra for grøn energi.',
            'date' => '2026-04-02',
        ],
        'da-03' => [
            'title' => 'Dansk mad og madkultur',
            'body' => 'Den danske madkultur har gennemgået en revolution de seneste årtier. Smørrebrød er en klassisk dansk ret, der består af rugbrød med forskellige pålæg som sild, leverpostej eller ost. Det nye nordiske køkken har sat Danmark på det gastronomiske verdenskort med fokus på lokale og sæsonbetonede råvarer. Restauranter som Noma i København har inspireret kokke over hele verden til at eksperimentere med fermentering og vilde planter. Rugbrød er fortsat et fast element i den danske kost og spises til både frokost og aftensmad. Wienerbrød, på trods af sit navn, er en dansk opfindelse og er populær over hele verden.',
            'date' => '2026-04-03',
        ],
        'da-04' => [
            'title' => 'Det danske velfærdssystem',
            'body' => 'Danmark er kendt for sit veludviklede velfærdssystem, der sikrer borgerne adgang til gratis uddannelse og sundhedspleje. Uddannelsessystemet i Danmark er gratis for alle borgere, og der ydes endda SU til studerende på de videregående uddannelser. Sundhedsvæsenet er finansieret over skatten og giver alle borgere adgang til behandling uanset indkomst. Den danske model kombinerer et fleksibelt arbejdsmarked med et stærkt socialt sikkerhedsnet, kaldet flexicurity. Pensionssystemet i Danmark er baseret på en kombination af folkepension og arbejdsmarkedspensioner. Det høje skatteniveau er forudsætningen for opretholdelse af velfærdssamfundets mange ydelser.',
            'date' => '2026-04-04',
        ],
        'da-05' => [
            'title' => 'Dansk sprog og litteratur',
            'body' => 'Det danske sprog tilhører den nordiske sprogfamilie og er tæt beslægtet med norsk og svensk. Dansk har mange dialekter, men rigsdansk bruges som fælles standard på tværs af landet. H.C. Andersen er den mest kendte danske forfatter, og hans eventyr som Den lille havfrue og Den grimme ælling er oversat til mere end 125 sprog. Karen Blixen, der skrev under pseudonymet Isak Dinesen, er en anden stor dansk forfatter kendt for Out of Africa. Det dansk sprog har mange lånord fra især tysk og engelsk, men arbejder aktivt på at bevare sit eget vokabular. Dansk litteratur har en rig tradition, der strækker sig fra middelalderens heltedigte til moderne skønlitteratur.',
            'date' => '2026-04-05',
        ],
    ],

    // Dutch - art/culture theme
    'nl' => [
        'nl-01' => [
            'title' => 'Nederlandse kunst en schilderkunst',
            'body' => 'Nederland heeft een rijke traditie op het gebied van beeldende kunst, die teruggaat tot de Gouden Eeuw in de zeventiende eeuw. Rembrandt van Rijn was een van de grootste meesters van de barok en staat bekend om zijn meesterlijke gebruik van licht en schaduw. Johannes Vermeer is beroemd om zijn intieme interieurscènes met prachtig geschilderd licht, waarvan het Meisje met de Parel het bekendste voorbeeld is. Vincent van Gogh, die in de negentiende eeuw leefde, liet een indrukwekkend oeuvre achter van meer dan 900 schilderijen. Het Rijksmuseum in Amsterdam herbergt een van de grootste collecties Nederlandse kunst ter wereld. Mondriaan ontwikkelde het neoplasticisme, een abstracte stijl met horizontale en verticale lijnen in primaire kleuren.',
            'date' => '2026-04-01',
        ],
        'nl-02' => [
            'title' => 'De Nederlandse waterhuishouding',
            'body' => 'Nederland is wereldberoemd om zijn strijd tegen het water en zijn ingenieuze waterbeheer. Bijna een derde van het land ligt onder zeeniveau en zou zonder dijken regelmatig overstromen. De Deltawerken zijn een van de grootste waterbouwkundige projecten ter wereld en beschermen het land tegen overstromingen. Molens werden oorspronkelijk gebruikt om water uit de polders te pompen en zijn nog altijd een symbool van Nederland. De waterschappen zijn de oudste democratische bestuursvorm van Nederland en beheren het regionale waterbeheer. Door de klimaatverandering neemt het risico op overstromingen toe, wat nieuwe investeringen in waterdefensie noodzakelijk maakt.',
            'date' => '2026-04-02',
        ],
        'nl-03' => [
            'title' => 'Amsterdam als wereldstad',
            'body' => 'Amsterdam is de hoofdstad en grootste stad van Nederland, bekend om zijn grachtengordel en rijke cultuur. De grachtengordel, aangelegd in de Gouden Eeuw, staat op de UNESCO Werelderfgoedlijst. Het Anne Frank Huis trekt jaarlijks meer dan een miljoen bezoekers en vertelt het aangrijpende verhaal van de Joodse meisje dat zich tijdens de Tweede Wereldoorlog verborg. Amsterdam heeft een levendige muziekscene en herbergt internationale festivals zoals het Amsterdam Dance Event. De stad heeft meer fietsen dan inwoners en het fietspad netwerk is een inspiratie voor steden wereldwijd. Amsterdams havengebied is in de afgelopen decennia getransformeerd tot een bruisend woon- en werkgebied.',
            'date' => '2026-04-03',
        ],
        'nl-04' => [
            'title' => 'Nederlandse tulpen en bloemenkwekerij',
            'body' => 'Nederland is de grootste producent en exporteur van bloemen en planten ter wereld. De tulp, oorspronkelijk afkomstig uit Centraal-Azië, is uitgegroeid tot het nationale symbool van Nederland. In de zeventiende eeuw leidde speculatie met tulpenbollen tot de eerste speculatiezeepbel in de geschiedenis, bekend als de Tulpenmanie. De Bloemenveiling in Aalsmeer is de grootste bloemenveiling ter wereld en verhandelt dagelijks miljoenen bloemen. De Keukenhof bij Lisse is het beroemdste bloemenparkparken ter wereld en trekt jaarlijks miljoenen toeristen. Nederlandse kwekers exporteren bloemenbollen en zaden naar meer dan honderd landen wereldwijd.',
            'date' => '2026-04-04',
        ],
        'nl-05' => [
            'title' => 'De Nederlandse taal en literatuur',
            'body' => 'Het Nederlands is een West-Germaanse taal die gesproken wordt door ongeveer 24 miljoen mensen, voornamelijk in Nederland en België. De Nederlandse literatuur heeft een rijke geschiedenis, van middeleeuwse ridderromans tot hedendaagse romans en poëzie. Harry Mulisch was een van de grootste naoorlogse schrijvers, bekend om zijn roman De Aanslag. W.F. Hermans en Gerard Reve behoren ook tot de grote drie van de Nederlandse naoorlogse literatuur. Multatuli schreef Max Havelaar, een aanklacht tegen het koloniale systeem in Nederlands-Indië die tot op de dag van vandaag gelezen wordt. De Nederlandse taal heeft zowel door de zeevaart als door de koloniale periode veel woorden aan andere talen gegeven.',
            'date' => '2026-04-05',
        ],
    ],

    // English - science theme
    'en' => [
        'en-01' => [
            'title' => 'Introduction to Quantum Physics',
            'body' => 'Quantum physics describes the behavior of matter and energy at the smallest scales. Unlike classical physics, quantum mechanics reveals that particles can exist in multiple states simultaneously through a phenomenon called superposition. The uncertainty principle, formulated by Werner Heisenberg, states that certain pairs of physical properties cannot be simultaneously measured with arbitrary precision. Quantum entanglement allows particles to be correlated in ways that transcend classical physics, enabling instantaneous information sharing across vast distances. Applications of quantum physics include lasers, transistors, MRI machines, and emerging quantum computers. The field continues to challenge our intuitions about the nature of reality and causality.',
            'date' => '2026-04-01',
        ],
        'en-02' => [
            'title' => 'Climate Science and Global Warming',
            'body' => 'Climate science studies the Earth\'s atmosphere, oceans, and land surface to understand long-term weather patterns. Human activities, particularly the burning of fossil fuels, have dramatically increased the concentration of greenhouse gases in the atmosphere. Carbon dioxide, methane, and nitrous oxide trap heat and raise global temperatures, leading to climate change. Scientists use sophisticated computer models to predict future climate scenarios under different emissions pathways. Rising sea levels threaten coastal communities, while changing precipitation patterns affect agriculture worldwide. International agreements like the Paris Agreement aim to limit global warming to below two degrees Celsius above pre-industrial levels.',
            'date' => '2026-04-02',
        ],
        'en-03' => [
            'title' => 'The Human Genome and Genetics',
            'body' => 'The human genome contains approximately three billion base pairs of DNA organized into 23 pairs of chromosomes. The Human Genome Project, completed in 2003, provided the first comprehensive map of human genetic material. Genes encode the instructions for building proteins, which carry out most biological functions in cells. Genetic mutations can cause diseases ranging from cystic fibrosis to various forms of cancer. CRISPR-Cas9 technology has revolutionized genetic engineering by allowing precise editing of DNA sequences. Personalized medicine uses genetic information to tailor treatments to individual patients, improving efficacy and reducing side effects.',
            'date' => '2026-04-03',
        ],
        'en-04' => [
            'title' => 'Astronomy and the Solar System',
            'body' => 'Our solar system formed about 4.6 billion years ago from a collapsing cloud of gas and dust. The Sun contains over 99 percent of the total mass of the solar system and provides the energy that sustains life on Earth. The eight planets orbit the Sun in roughly circular elliptical paths, from Mercury closest to the Sun to Neptune farthest away. Mars has been the target of numerous robotic missions seeking signs of past or present life. Jupiter is the largest planet and its Great Red Spot is a storm that has persisted for centuries. Astronomers have discovered thousands of exoplanets orbiting other stars, some of which may harbor conditions suitable for life.',
            'date' => '2026-04-04',
        ],
        'en-05' => [
            'title' => 'Evolution and Natural Selection',
            'body' => 'Charles Darwin\'s theory of evolution by natural selection is the unifying framework of modern biology. Natural selection operates when heritable variation leads to differential survival and reproduction among individuals in a population. Genetic mutations introduce new variation, while gene flow and genetic drift also shape the diversity of life. The fossil record provides compelling evidence for the evolution of species over geological time. Comparative genomics has confirmed that all life on Earth shares common ancestry through shared DNA sequences. Modern evolutionary theory incorporates genetics, developmental biology, and ecology to explain the remarkable diversity of organisms on our planet.',
            'date' => '2026-04-05',
        ],
    ],

    // Finnish - nature/forests theme
    'fi' => [
        'fi-01' => [
            'title' => 'Suomen metsät ja luonto',
            'body' => 'Suomi on metsien maa, sillä metsät peittävät noin 75 prosenttia maan pinta-alasta. Suomalaiset metsät ovat pääasiassa havumetsiä, joissa kasvaa kuusia, mäntyjä ja koivuja. Metsillä on keskeinen rooli Suomen taloudessa, sillä metsäteollisuus on yksi tärkeimmistä vientiteollisuuden aloista. Kansallispuistot suojelevat arvokkaimpia luontokohteita ja tarjoavat asukkaille ja matkailijoille mahdollisuuden nauttia koskemattomasta luonnosta. Suomessa on enemmän järviä kuin missään muussa maassa: yli 180 000 järveä. Poronhoito on tärkeä elinkeino Lapissa, ja porot vaeltavat vapaasti Pohjois-Suomen laajoilla tundraoilla.',
            'date' => '2026-04-01',
        ],
        'fi-02' => [
            'title' => 'Suomalainen saunakulttuurii',
            'body' => 'Sauna on tärkeä osa suomalaista kulttuuria ja identiteettiä. Suomessa on arviolta kolme miljoonaa saunaa yli viiden miljoonan asukkaan maassa. Saunominen on perinteisesti ollut paikka puhdistautumiselle, rentoutumiselle ja sosiaaliselle kanssakäymiselle. Perinteinen suomalainen sauna lämmitetään puulla, ja kiukaalle heitetään vettä löylyksi kuuman höyryn muodostamiseksi. Talvella on perinteistä hypätä saunan jälkeen avantoon eli jääkylmään veteen. UNESCO lisäsi suomalaisen saunakulttuurin aineettoman kulttuuriperinnön luetteloonsa vuonna 2020. Sauna on paikka, jossa kaikki ovat tasa-arvoisia riippumatta yhteiskunnallisesta asemasta.',
            'date' => '2026-04-02',
        ],
        'fi-03' => [
            'title' => 'Suomen koulujärjestelmä',
            'body' => 'Suomen koulutusjärjestelmä on maailman parhaiden joukossa, minkä kansainväliset tutkimukset ovat toistuvasti osoittaneet. Perusopetus on pakollinen ja ilmainen kaikille lapsille seitsemänvuotiaasta lähtien. Suomalaisessa koulutusjärjestelmässä ei oteta käyttöön standardoituja testejä ennen yhdeksättä luokkaa, ja painopiste on oppimisen ilossa eikä kilpailussa. Opettajan ammatti on Suomessa arvostettu, ja siihen hakee suuri joukko pätevöityneitä hakijoita. Korkeakoulutus on ilmainen ja avointa kaikille, jotka läpäisevät pääsyvaatimukset. Elinikäinen oppiminen on keskeinen periaate, ja aikuiskoulutusta tarjotaan laajasti eri puolilla maata.',
            'date' => '2026-04-03',
        ],
        'fi-04' => [
            'title' => 'Suomalainen musiikki ja taide',
            'body' => 'Suomalaisella musiikilla on vahva perinne kansanlaulusta klassiseen musiikkiin ja rockiin. Jean Sibelius on Suomen tunnetuin säveltäjä, jonka Finlandia-sinfoniapoemaa pidetään Suomen epävirallisena kansallislauluna. Suomalainen metallimusiikki on saavuttanut maailmanlaajuista mainetta, ja yhtyeet kuten Nightwish ja HIM ovat myyneet miljoonia levyjä. Arkkitehtuuri on tärkeä osa suomalaista taidetta, ja Alvar Aalto on yksi maailman vaikutusvaltaisimmista arkkitehdeistä. Suomalaiset kirjailijat kuten Tove Jansson ja Aleksis Kivi ovat jättäneet pysyvän jäljen maailmankirjallisuuteen. Taiteen perusopetus tavoittaa suuren osan suomalaisista lapsista ja nuorista.',
            'date' => '2026-04-04',
        ],
        'fi-05' => [
            'title' => 'Suomen talous ja teknologia',
            'body' => 'Suomi on kehittynyt maatalousvaltaisesta yhteiskunnasta moderniksi teknologiamaaksi muutaman vuosikymmenen kuluessa. Nokia oli 1990- ja 2000-luvuilla maailman suurin matkapuhelinvalmistaja ja muovasi Suomesta teknologiamaan brändiä. Suomen startup-ekosysteemi on kukoistava, ja Helsinki on yksi Euroopan johtavista startup-kaupungeista. Teollisuuden digitalisaatio ja kestävä kehitys ovat keskeisiä teemoja suomalaisessa elinkeinoelämässä. Suomi investoi tutkimukseen ja kehittämiseen suhteellisesti enemmän kuin useimmat muut maat. Metsäteollisuus, konepajateollisuus ja cleantech ovat suomalaisen talouden keskeisiä tukipilareita.',
            'date' => '2026-04-05',
        ],
    ],

    // French - literature/arts theme
    'fr' => [
        'fr-01' => [
            'title' => 'La littérature française du XIXe siècle',
            'body' => 'Le XIXe siècle fut une époque particulièrement riche pour la littérature française. Le romantisme, mouvement littéraire et artistique né en réaction au rationalisme des Lumières, donna naissance à des œuvres majeures. Victor Hugo, figure emblématique du romantisme, écrivit Notre-Dame de Paris et Les Misérables, œuvres qui continuent de captiver les lecteurs du monde entier. Gustave Flaubert révolutionna le roman avec Madame Bovary, chefœuvre du réalisme littéraire. Émile Zola fonda le naturalisme avec la saga des Rougon-Macquart, dépeignant la société française sous le Second Empire. La poésie fut également transformée par Baudelaire et ses Fleurs du mal, qui ouvrirent la voie au symbolisme.',
            'date' => '2026-04-01',
        ],
        'fr-02' => [
            'title' => 'La gastronomie française dans le monde',
            'body' => 'La gastronomie française est reconnue dans le monde entier pour sa sophistication et sa richesse. La cuisine française fut codifiée au XIXe siècle par Auguste Escoffier, qui établit les bases de la haute cuisine moderne. Le repas gastronomique des Français a été inscrit au patrimoine culturel immatériel de l\'humanité par l\'UNESCO en 2010. Les vins français, notamment ceux de Bordeaux, de Bourgogne et de Champagne, sont parmi les plus appréciés et les plus collectionnés au monde. Le fromage est au cœur de la culture alimentaire française, avec plus de 1200 variétés recensées sur le territoire. Les marchés locaux, présents dans chaque ville et village, témoignent de l\'attachement des Français aux produits frais et de saison.',
            'date' => '2026-04-02',
        ],
        'fr-03' => [
            'title' => 'L\'architecture française à travers les siècles',
            'body' => 'L\'architecture française reflète l\'histoire mouvementée du pays à travers les siècles. Les cathédrales gothiques, comme Notre-Dame de Paris et la cathédrale de Chartres, témoignent du génie des bâtisseurs médiévaux. Le château de Versailles, construit sous Louis XIV, est le symbole du classicisme français et de la monarchie absolue. L\'architecture haussmannienne a profondément remodelé Paris au XIXe siècle, créant les grands boulevards et les immeubles aux façades uniformes. La Tour Eiffel, construite pour l\'Exposition universelle de 1889, est devenue le symbole de Paris et l\'un des monuments les plus visités au monde. L\'architecture contemporaine française se distingue par des projets audacieux comme la Pyramide du Louvre et le Centre Pompidou.',
            'date' => '2026-04-03',
        ],
        'fr-04' => [
            'title' => 'La philosophie française des Lumières',
            'body' => 'Le mouvement des Lumières, né en France au XVIIIe siècle, a profondément marqué la pensée occidentale. Voltaire, avec son esprit critique et son ironie, s\'attaqua aux dogmes religieux et aux abus du pouvoir dans des œuvres comme Candide. Jean-Jacques Rousseau développa ses théories sur la nature humaine et le contrat social, influençant profondément la Révolution française. Montesquieu analysa les différentes formes de gouvernement dans De l\'Esprit des lois et théorisa la séparation des pouvoirs. L\'Encyclopédie, dirigée par Diderot et d\'Alembert, rassembla et diffusa le savoir de l\'époque avec un esprit critique. Ces idées révolutionnaires contribuèrent à façonner les démocraties modernes et les droits de l\'homme.',
            'date' => '2026-04-04',
        ],
        'fr-05' => [
            'title' => 'Le cinéma français contemporain',
            'body' => 'Le cinéma français a une longue et illustre histoire qui remonte aux frères Lumière, inventeurs du cinématographe en 1895. La Nouvelle Vague des années 1960, avec des réalisateurs comme François Truffaut et Jean-Luc Godard, révolutionna l\'art cinématographique mondial. Le cinéma français contemporain continue de se distinguer par sa diversité et son ambition artistique. Des films comme La Vie en rose, Amélie Poulain et Les Intouchables ont connu un succès international considérable. Le Festival de Cannes, créé en 1946, est l\'un des événements cinématographiques les plus prestigieux au monde. La France subventionne son cinéma pour préserver la diversité culturelle face à la domination hollywoodienne.',
            'date' => '2026-04-05',
        ],
    ],

    // German - science/Wissenschaft theme
    'de' => [
        'de-01' => [
            'title' => 'Die deutsche Wissenschaftsgeschichte',
            'body' => 'Deutschland blickt auf eine bedeutende wissenschaftliche Tradition zurück, die das Wissen der Menschheit entscheidend geprägt hat. Johannes Kepler entdeckte die Gesetze der Planetenbewegung im frühen 17. Jahrhundert und legte damit den Grundstein für die moderne Astronomie. Carl Friedrich Gauß revolutionierte die Mathematik mit seinen Beiträgen zur Zahlentheorie, Statistik und Geometrie. Albert Einstein entwickelte die spezielle und allgemeine Relativitätstheorie, die unser Verständnis von Raum, Zeit und Schwerkraft grundlegend veränderte. Max Planck begründete die Quantenphysik mit der Entdeckung, dass Energie nur in diskreten Einheiten übertragen wird. Werner Heisenberg und Erwin Schrödinger entwickelten die mathematischen Grundlagen der Quantenmechanik.',
            'date' => '2026-04-01',
        ],
        'de-02' => [
            'title' => 'Technologie und Industrie in Deutschland',
            'body' => 'Deutschland ist bekannt für seine leistungsstarke Industrie und herausragende technologische Innovationen. Die deutsche Automobilindustrie mit Unternehmen wie BMW, Mercedes-Benz und Volkswagen genießt weltweit einen ausgezeichneten Ruf. Der Maschinenbau ist eine weitere Kernkompetenz der deutschen Wirtschaft, und deutsche Präzisionsmaschinen werden in alle Welt exportiert. Die Chemieindustrie, vertreten durch Konzerne wie BASF und Bayer, ist ein weiterer Pfeiler der deutschen Wirtschaft. Die erneuerbaren Energien gewinnen in Deutschland zunehmend an Bedeutung, und die Energiewende ist ein ehrgeiziges politisches Ziel. Digitalisierung und Industrie 4.0 sind zentrale Themen in der deutschen Wirtschafts- und Technologiepolitik.',
            'date' => '2026-04-02',
        ],
        'de-03' => [
            'title' => 'Deutsche Literatur und Philosophie',
            'body' => 'Die deutsche Literatur und Philosophie haben die Weltkultur maßgeblich beeinflusst. Johann Wolfgang von Goethe gilt als einer der bedeutendsten Dichter der deutschen Sprache, sein Faust ist ein Meisterwerk der Weltliteratur. Friedrich Schiller bereicherte die deutsche Literatur mit Dramen wie Wilhelm Tell und Don Karlos. Immanuel Kant revolutionierte die Philosophie mit seiner Kritik der reinen Vernunft und der Kritik der praktischen Vernunft. Georg Wilhelm Friedrich Hegel entwickelte die Dialektik als philosophische Methode und hatte großen Einfluss auf Marx und andere Denker. Friedrich Nietzsche forderte mit seiner Philosophie die traditionellen Werte und die christliche Moral heraus.',
            'date' => '2026-04-03',
        ],
        'de-04' => [
            'title' => 'Architektur und Bauhaus in Deutschland',
            'body' => 'Deutschland hat eine reiche architektonische Tradition von der Romanik bis zur Moderne. Das Bauhaus, gegründet 1919 von Walter Gropius in Weimar, war eine der einflussreichsten Designschulen der Welt. Der Bauhausstil, der Form und Funktion vereinte, beeinflusste Architektur und Design weltweit bis heute. Die Kölner Kathedrale ist ein herausragendes Beispiel der gotischen Architektur und ein UNESCO-Weltkulturerbe. Schloss Sanssouci in Potsdam und Schloss Neuschwanstein in Bayern sind weltberühmte Beispiele des barocken und neugotischen Baustils. Das Berliner Olympiastadion und die Olympiahalle in München zeigen das Können moderner deutscher Architektur.',
            'date' => '2026-04-04',
        ],
        'de-05' => [
            'title' => 'Die deutsche Sprache und ihre Besonderheiten',
            'body' => 'Die deutsche Sprache gehört zur westgermanischen Sprachfamilie und wird von etwa 130 Millionen Menschen weltweit gesprochen. Deutsch ist bekannt für seine langen zusammengesetzten Wörter, die aus mehreren Wörtern zusammengesetzt sind, wie zum Beispiel Donaudampfschifffahrtsgesellschaft. Das Deutsche hat vier Fälle: Nominativ, Akkusativ, Dativ und Genitiv, was es für Lernende anspruchsvoller macht als viele andere Sprachen. Der Duden ist das maßgebliche Wörterbuch der deutschen Sprache und wird regelmäßig aktualisiert. Martin Luthers Bibelübersetzung legte im 16. Jahrhundert den Grundstein für die neuhochdeutsche Schriftsprache. Die deutschen Dialekte variieren stark von Region zu Region, von Bairisch bis Plattdeutsch.',
            'date' => '2026-04-05',
        ],
    ],

    // Hungarian - history/culture theme
    'hu' => [
        'hu-01' => [
            'title' => 'Magyarország története és kultúrája',
            'body' => 'Magyarország gazdag történelmi múltra tekint vissza az évezredek során. A honfoglalás 896-ban zajlott le, amikor a magyarok megtelepedtek a Kárpát-medencében. Szent István király 1000-ben alapította meg a keresztény Magyar Királyságot, és a nyugat-európai feudális rendszert vezette be. A tatárjárás 1241-ben súlyos pusztítást okozott az országban, de Magyarország hamarosan talpra állt. A török hódoltság kora másfél évszázadig tartott, és mélyreható változásokat hozott az ország életében. A Habsburg-uralom alatt Magyarország fokozatosan visszanyerte önállóságát, amit az 1848-49-es forradalom jelképez.',
            'date' => '2026-04-01',
        ],
        'hu-02' => [
            'title' => 'Budapest, a Duna gyöngye',
            'body' => 'Budapest Magyarország fővárosa és az ország gazdasági, kulturális és politikai központja. A város 1873-ban jött létre Buda, Óbuda és Pest egyesítésével. A Duna mindkét partján elterülő városban számos UNESCO világörökségi helyszín található, köztük a Budai Vár és a Parlament épülete. A Széchenyi Lánchíd, amelyet 1849-ben avattak fel, az első állandó híd volt a Duna két partja között. Budapest fürdőváros hírnevét termálforrásai alapozzák meg, amelyek gyógyhatásáról már a rómaiak is tudtak. A Hősök tere a millennium emlékére emelt tér, ahol Magyarország nagy királyainak szobrai állnak.',
            'date' => '2026-04-02',
        ],
        'hu-03' => [
            'title' => 'Magyar zene és Bartók Béla',
            'body' => 'A magyar zenetörténet kiemelkedő alkotókkal és gazdag népi zenei hagyományokkal büszkélkedhet. Bartók Béla a 20. század egyik legnagyobb zenekomponistája volt, aki a népi zenét beemelte a klasszikus zene világába. Kodály Zoltán pedagógiai módszere, a Kodály-módszer, a zenei nevelés terén ma is világszerte ismert és alkalmazott. Franz Liszt a romantika kiemelkedő zeneszerzője és virtuóz zongorista volt, aki a csárdást a szimfonikus zene szintjére emelte. A magyar népzene rendkívül gazdag és változatos, régiók és etnikumok szerint eltérő jellegzetességeket mutat. A Budapest Jazz Festival és a Sziget Fesztivál Magyarország legjelentősebb zenei rendezvényei közé tartoznak.',
            'date' => '2026-04-03',
        ],
        'hu-04' => [
            'title' => 'A magyar konyha és gasztronómia',
            'body' => 'A magyar konyha az egész világon ismert és kedvelt, különösen a pörkölt, a gulyásleves és a halászlé. A piros paprika a magyar konyha legfontosabb fűszere, amely számos ételnek adja meg a jellegzetes ízét és színét. A gulyásleves eredetileg a pásztorok étele volt, mára azonban a magyar konyha egyik szimbólumává vált. A lángos egy süthető tésztából készült utcai étel, amelyet tejföllel és sajttal tálalnak. A tokaji borok, különösen az aszú, a világ leghíresebb borai közé tartoznak, és a királyok italának nevezték őket. A kürtőskalács, egy forgatott édes tészta, az egyik legismertebb magyar édesség mind belföldön, mind külföldön.',
            'date' => '2026-04-04',
        ],
        'hu-05' => [
            'title' => 'Magyar irodalom és költészet',
            'body' => 'A magyar irodalom gazdag hagyományokkal rendelkezik, amelyek az ősi mondáktól a kortárs prózáig terjednek. Petőfi Sándor a 19. századi forradalom romantikus költője volt, akinek verseit ma is kötelező olvasmányként tanítják az iskolákban. Arany János az epikus költészet nagymestere volt, a Toldi trilógia egyik legnagyobb alkotás a magyar irodalomban. Ady Endre a 20. századi modern irodalom úttörője volt, aki merész képekkel és szimbolizmussal forradalmasította a magyar lírát. Márai Sándor regényei és esszéi nagy irodalmi értéket képviselnek, és magyarul és számos más nyelven is olvasottak. Imre Kertész 2002-ben irodalmi Nobel-díjat kapott a holokausztról szóló Sorstalanság és más regényeiért.',
            'date' => '2026-04-05',
        ],
    ],

    // Italian - art/Renaissance theme
    'it' => [
        'it-01' => [
            'title' => 'Il Rinascimento italiano',
            'body' => 'Il Rinascimento fu uno dei periodi più straordinari della storia della cultura occidentale, con epicentro in Italia tra il XIV e il XVI secolo. Firenze fu il cuore pulsante di questo movimento grazie al mecenatismo dei Medici, potente famiglia di banchieri. Leonardo da Vinci incarnò l\'ideale dell\'uomo universale, eccellendo come artista, scienziato, ingegnere e inventore. Michelangelo lasciò un\'impronta indelebile nell\'arte mondiale con la Cappella Sistina, il David e la Pietà. Raffaello Sanzio, con le sue Stanze vaticane e i ritratti eleganti, sintetizzò la bellezza e l\'armonia dell\'arte rinascimentale. Il pensiero umanistico, che poneva l\'uomo al centro dell\'universo, segnò una rottura decisiva con la visione medievale.',
            'date' => '2026-04-01',
        ],
        'it-02' => [
            'title' => 'La cucina italiana nel mondo',
            'body' => 'La cucina italiana è una delle più apprezzate e diffuse al mondo, con una varietà di piatti regionali unici. La pasta, in tutte le sue forme, è il simbolo per eccellenza della gastronomia italiana e viene consumata quotidianamente in ogni angolo del paese. La pizza napoletana, riconosciuta dall\'UNESCO come patrimonio culturale immateriale, ha conquistato ogni continente. Il caffè espresso è un rituale culturale profondamente radicato nella vita quotidiana degli italiani. I vini italiani, dal Barolo al Chianti, dal Brunello al Prosecco, sono tra i più rinomati e apprezzati nel panorama enologico mondiale. La cucina italiana si caratterizza per l\'uso di ingredienti freschi e di qualità, con ricette tramandate di generazione in generazione.',
            'date' => '2026-04-02',
        ],
        'it-03' => [
            'title' => 'Roma: storia e architettura',
            'body' => 'Roma è una delle città più antiche e ricche di storia del mondo, con oltre 2500 anni di storia ininterrotta. Il Colosseo, costruito tra il 72 e l\'80 d.C., è il più grande anfiteatro del mondo antico e simbolo della potenza di Roma. Il Pantheon, con la sua cupola ottagonale, è uno degli edifici meglio conservati dell\'antichità e ha influenzato tutta l\'architettura occidentale. I Fori Romani offrono uno straordinario viaggio nel cuore della vita pubblica dell\'antica Roma. La Basilica di San Pietro in Vaticano è la più grande chiesa del mondo ed è meta di milioni di pellegrini ogni anno. La Fontana di Trevi e Piazza Navona sono luoghi simbolo del barocco romano e della bellezza urbana della città.',
            'date' => '2026-04-03',
        ],
        'it-04' => [
            'title' => 'La musica italiana dalla lirica al moderno',
            'body' => 'L\'Italia vanta una delle tradizioni musicali più ricche e influenti della storia mondiale. La tradizione operistica italiana iniziò nel XVI secolo e raggiunse i suoi vertici con compositori come Verdi, Rossini e Puccini. Giuseppe Verdi, con opere come il Rigoletto, La Traviata e l\'Aida, è considerato uno dei più grandi compositori di tutti i tempi. La voce di Luciano Pavarotti, con la sua straordinaria potenza e dolcezza, ha reso l\'opera accessibile a milioni di persone in tutto il mondo. Il bel canto è uno stile vocale caratteristico italiano che si distingue per la purezza del tono e la fluidità delle linee melodiche. Nella musica contemporanea, l\'Italia ha dato i natali ad artisti di fama internazionale come Eros Ramazzotti e Andrea Bocelli.',
            'date' => '2026-04-04',
        ],
        'it-05' => [
            'title' => 'La moda italiana nel mondo',
            'body' => 'L\'Italia è la capitale mondiale della moda, con Milano che ospita una delle fashion week più importanti del pianeta. Case di moda come Gucci, Prada, Versace, Armani e Dolce e Gabbana hanno reso il made in Italy un marchio di eccellenza riconosciuto ovunque. La tradizione artigianale italiana, con la lavorazione del cuoio a Firenze e della seta a Como, è alla base dell\'eccellenza stilistica italiana. Il concetto di bella figura è profondamente radicato nella cultura italiana e si riflette nell\'attenzione all\'abbigliamento e all\'estetica. Il design italiano va ben oltre la moda e abbraccia il settore dell\'arredamento, dell\'automobile e del design industriale. Il Salone del Mobile di Milano è il più importante evento mondiale del design e dell\'arredamento.',
            'date' => '2026-04-05',
        ],
    ],

    // Japanese - tradition/culture theme
    'ja' => [
        'ja-01' => [
            'title' => '日本の伝統文化と芸術',
            'body' => '日本の伝統文化は何世紀にもわたって独自の発展を遂げてきました。茶道は単なる茶の飲み方ではなく、精神的な修行と美的感覚を追求する総合芸術です。生け花は花を用いた造形芸術で、自然との調和と美しさを表現します。能楽は世界最古の舞台芸術の一つであり、面と衣装を用いた幽玄な美しさが特徴です。歌舞伎は江戸時代に発展した伝統演劇で、独特の化粧と衣装が印象的です。日本庭園は自然の景観を模倣しながら、枯山水や池泉などの様式で独特の美を創り出します。武士道の精神は現代日本人の価値観にも深く根付いています。',
            'date' => '2026-04-01',
        ],
        'ja-02' => [
            'title' => '日本の食文化と料理',
            'body' => '日本の食文化は多様性と繊細さで世界的に高い評価を得ています。寿司は生魚と酢飯を組み合わせた代表的な日本料理で、世界中で愛されています。ラーメンは中国の影響を受けつつも日本独自の発展を遂げ、地域によって様々なスタイルがあります。日本の食文化の特徴の一つは旬の食材を大切にすることで、季節ごとの味覚を楽しみます。出汁は日本料理の基本となる旨味成分で、昆布や鰹節から丁寧に引かれます。和菓子は季節を表現する繊細な菓子で、抹茶との相性が良く、日本の美意識を体現しています。食事の作法や器の選び方も日本料理の重要な要素です。',
            'date' => '2026-04-02',
        ],
        'ja-03' => [
            'title' => '日本の技術革新と産業',
            'body' => '日本は戦後の驚異的な経済成長を通じて、世界有数の技術大国へと発展しました。自動車産業はトヨタ、ホンダ、日産などの企業が世界市場をリードしています。電子機器分野では、ソニーやパナソニックなどが革新的な製品を生み出してきました。新幹線は1964年に開業した世界初の高速鉄道であり、現在も技術の粋を尽くした輸送システムです。ロボット工学において日本は世界をリードしており、製造業から医療まで幅広い分野で活用されています。省エネルギー技術やハイブリッド車の開発においても日本は世界的な先進国です。近年はAIや量子コンピューターなどの最先端技術への投資も積極的に行われています。',
            'date' => '2026-04-03',
        ],
        'ja-04' => [
            'title' => '日本の自然と四季',
            'body' => '日本は南北に長い島国であり、地域によって大きく異なる気候と自然を持っています。春の桜は日本人にとって特別な存在であり、花見の文化は古くから根付いています。夏は祭りの季節であり、花火大会や盆踊りなど各地で様々な行事が開催されます。秋の紅葉は日本の風景に彩りを添え、京都や日光など各地の名所に多くの観光客が訪れます。冬は北海道で雪まつりが開催され、美しい雪像が観光客を魅了します。富士山は日本の象徴的な山であり、世界文化遺産にも登録されています。日本各地には温泉地があり、四季折々の自然を楽しみながら湯治を楽しむ文化があります。',
            'date' => '2026-04-04',
        ],
        'ja-05' => [
            'title' => '現代日本の社会と文化',
            'body' => '現代日本は伝統と革新が共存する独特の社会を形成しています。アニメやマンガは日本を代表するポップカルチャーとして世界中にファンを持ちます。ゲーム産業においても任天堂や Sony などの企業が世界をリードしています。日本の教育システムは高い学力水準を誇り、特に数学と科学の分野で優秀な成績を収めています。少子高齢化は現代日本の最も重要な社会課題の一つであり、様々な政策的対応が求められています。日本の交通網は世界でも最も発達したものの一つで、鉄道の時間の正確さは国際的に評価されています。環境問題への意識も高く、リサイクルや省エネルギーへの取り組みは市民生活に深く浸透しています。',
            'date' => '2026-04-05',
        ],
    ],

    // Korean - society/culture theme
    'ko' => [
        'ko-01' => [
            'title' => '한국의 역사와 문화',
            'body' => '한국은 5천 년의 오랜 역사를 가진 나라로 고조선, 삼국시대, 고려, 조선 등 여러 왕조를 거쳐 발전했습니다. 한글은 세종대왕이 1443년에 창제한 과학적인 문자 체계로 세계 언어학자들에게 높은 평가를 받습니다. 불교는 삼국시대에 전래되어 한국 문화와 예술에 깊은 영향을 미쳤으며 많은 유네스코 세계유산 사찰이 있습니다. 조선시대 유교는 사회 질서와 가치관의 근간이 되었으며 현대 한국 사회에도 그 영향이 남아 있습니다. 한국의 도자기, 특히 고려청자는 세계적으로 그 예술적 가치를 인정받고 있습니다. 태권도는 한국의 전통 무술로 현재 전 세계에 수천만 명의 수련자가 있습니다.',
            'date' => '2026-04-01',
        ],
        'ko-02' => [
            'title' => '한국의 음식 문화',
            'body' => '한국 음식은 건강에 좋은 재료와 다양한 발효 음식으로 세계적인 주목을 받고 있습니다. 김치는 배추나 무를 고춧가루와 함께 발효시킨 대표적인 한국 음식으로 건강에 좋은 유산균이 풍부합니다. 비빔밥은 밥 위에 여러 가지 나물과 고추장을 올려 비벼 먹는 음식으로 영양이 균형 잡혀 있습니다. 삼겹살은 돼지고기 삼겹살을 불판에 구워 상추에 싸서 먹는 방식으로 한국의 회식 문화에서 빠질 수 없습니다. 한국의 국물 요리는 다양하며 갈비탕, 설렁탕, 된장찌개 등이 대표적입니다. 식혜와 수정과는 한국의 전통 음료로 명절에 즐겨 마시는 건강 음료입니다.',
            'date' => '2026-04-02',
        ],
        'ko-03' => [
            'title' => '한류와 한국 대중문화',
            'body' => '한류는 한국의 대중문화가 전 세계로 확산되는 현상으로 1990년대 후반부터 시작되었습니다. BTS는 전 세계 음악 시장에서 큰 성공을 거두며 한국 음악의 국제적 위상을 높였습니다. 블랙핑크, 엑소, 트와이스 등 K-POP 그룹들이 아시아를 넘어 세계 각지에서 팬들을 모으고 있습니다. 한국 드라마는 아시아는 물론 유럽과 아메리카에서도 큰 인기를 끌고 있으며 넷플릭스를 통해 전 세계에 배포됩니다. 영화 기생충은 2020년 아카데미 시상식에서 작품상을 수상해 한국 영화의 예술성을 세계에 알렸습니다. K-뷰티로 알려진 한국의 화장품과 스킨케어 문화도 전 세계 뷰티 산업에 영향을 미치고 있습니다.',
            'date' => '2026-04-03',
        ],
        'ko-04' => [
            'title' => '한국의 교육 시스템',
            'body' => '한국은 교육열이 매우 높은 나라로 OECD 국가 중 대학 진학률이 가장 높은 편에 속합니다. 수능은 한국의 대학수학능력시험으로 매년 11월에 시행되며 학생들의 대학 입시를 결정하는 중요한 시험입니다. 학원이라 불리는 사교육 기관이 전국에 매우 많으며 한국 학생들은 방과 후에도 학원에서 추가 학습을 합니다. 한국의 교육 투자는 국가 발전의 원동력이 되어 짧은 시간 안에 세계적인 기업들을 탄생시켰습니다. 서울대학교, 연세대학교, 고려대학교는 한국의 3대 명문 대학으로 SKY라고 불립니다. 최근에는 창의성과 비판적 사고를 강조하는 방향으로 교육 개혁이 추진되고 있습니다.',
            'date' => '2026-04-04',
        ],
        'ko-05' => [
            'title' => '한국의 경제 발전',
            'body' => '한국은 한강의 기적이라 불리는 놀라운 경제 성장을 이루어 20세기 최고의 경제 발전 사례 중 하나로 꼽힙니다. 삼성전자, 현대자동차, LG전자 등 한국의 대기업들은 세계 시장에서 중요한 위치를 차지하고 있습니다. 반도체 산업에서 한국은 세계 최고 수준의 기술력을 보유하고 있으며 글로벌 공급망에서 핵심적인 역할을 담당합니다. 조선 산업에서도 한국은 세계 선두를 달리며 대형 선박과 LNG 운반선 분야에서 탁월한 경쟁력을 가지고 있습니다. 한국은 디지털 인프라가 세계 최고 수준으로 5G 네트워크 보급률과 인터넷 속도에서 세계 최상위권입니다. K-스타트업 생태계도 빠르게 성장하여 유니콘 기업들이 속속 등장하고 있습니다.',
            'date' => '2026-04-05',
        ],
    ],

    // Norwegian - nature/fjords theme
    'no' => [
        'no-01' => [
            'title' => 'Norges fjorder og natur',
            'body' => 'Norge er kjent for sine spektakulære fjorder, som er blant de vakreste naturlige formasjonene i verden. Sognefjorden er Norges lengste og dypeste fjord, og strekker seg 204 kilometer inn i landet. Geirangerfjorden og Nærøyfjorden er på UNESCO-verdensarvlisten på grunn av sin enestående skjønnhet. Den norske naturen er preget av kontraster, fra snødekte fjelltopper til grønne daler og kystlinje. Nordlyset, eller Aurora Borealis, er et magisk fenomen som kan oppleves i Nordnorge på klare vinterkvelder. Midnattsolen i sommer og den polare natten om vinteren er unike naturfenomener i de nordlige delene av landet.',
            'date' => '2026-04-01',
        ],
        'no-02' => [
            'title' => 'Norsk olje og energi',
            'body' => 'Norge er en av verdens største oljeprodusenter og eksportører, og oljeinntektene har hatt stor betydning for den norske velferdsstatens utvikling. Statens pensjonsfond utland, også kalt oljefondet, er verdens største statlige investeringsfond. Norge er også en ledende nasjon innen vannkraft, og nesten all elektrisitet i landet produseres fra fornybare kilder. Den norske stat eier en betydelig andel i oljeindustrien gjennom selskapet Equinor, tidligere kjent som Statoil. Norge satser nå tungt på havvind og andre fornybare energikilder for fremtiden. Petroleum har gitt nordmenn en av de høyeste levestandarder i verden.',
            'date' => '2026-04-02',
        ],
        'no-03' => [
            'title' => 'Det norske velferdssystemet',
            'body' => 'Norge har et av verdens mest sjenerøse velferdssystemer, som sikrer innbyggerne et bredt nett av sosiale tjenester. Helsevesenet er offentlig og finansiert over skatten, noe som sikrer lik tilgang til behandling for alle borgere. Utdanning er gratis på alle nivåer, fra barneskole til høyere utdanning. Foreldrepermisjonen i Norge er en av de beste i verden, med mulighet for både mor og far til å ta ut permisjon. Det norske arbeidsmarkedet er preget av høy fagorganisering og tett samarbeid mellom arbeidstakere, arbeidsgivere og myndighetene. Norge topper jevnlig FNs Human Development Index som et av verdens beste land å leve i.',
            'date' => '2026-04-03',
        ],
        'no-04' => [
            'title' => 'Norsk kultur og tradisjoner',
            'body' => 'Den norske kulturen er preget av sterke naturtradisjoner og friluftsliv. Friluftsliv er et begrep som beskriver nordmenns kjærlighet til å tilbringe tid utendørs i naturen. Allemannsretten gir enhver rett til å ferdes og oppholde seg i naturen, noe som er en viktig del av norsk kultur. Bunad er den norske nasjonaldrakten som bæres ved spesielle anledninger som nasjonaldagen 17. mai. Norsk litteratur er rik, med forfattere som Henrik Ibsen og Knut Hamsun som er blant Nordens mest kjente. Edvard Grieg er Norges mest berømte komponist, kjent for musikken til Peer Gynt og Lyriske stykker.',
            'date' => '2026-04-04',
        ],
        'no-05' => [
            'title' => 'Det norske språket og dialekter',
            'body' => 'Norsk er et skandinavisk språk som er nært beslektet med dansk og svensk. Norge har to offisielle skriftspråk, bokmål og nynorsk, som begge brukes i skriftlig kommunikasjon og offisielle dokumenter. Bokmål er det mest utbredte skriftspråket og brukes av omtrent 85 prosent av befolkningen. Nynorsk ble utviklet på 1800-tallet basert på norske dialekter og er særlig sterkt i Vestlandet. Norske dialekter varierer sterkt fra region til region, og dialektrikdommen er et unikt trekk ved norsk. Det er ingen offisiell norsk standarduttale, og folk oppfordres til å bruke sin lokale dialekt i alle sammenhenger.',
            'date' => '2026-04-05',
        ],
    ],

    // Portuguese - history/exploration theme
    'pt' => [
        'pt-01' => [
            'title' => 'As Grandes Navegações Portuguesas',
            'body' => 'Portugal foi a grande potência marítima do século XV, com descobertas que transformaram o mundo. Vasco da Gama abriu a rota marítima para a Índia em 1498, conectando a Europa ao comércio asiático pela primeira vez pelo mar. Pedro Álvares Cabral chegou ao Brasil em 1500, estabelecendo a base do maior país da América Latina. Fernão de Magalhães, a serviço da Coroa espanhola, iniciou a primeira viagem de circumnavegação da Terra em 1519. O Tratado de Tordesilhas de 1494 dividiu o mundo entre Portugal e Espanha, definindo zonas de influência colonial. Os navios caravelas, desenvolvidos pelos portugueses, eram ideais para explorar as costas africanas com sua vela latina triangular.',
            'date' => '2026-04-01',
        ],
        'pt-02' => [
            'title' => 'A língua portuguesa no mundo',
            'body' => 'O português é a quinta língua mais falada no mundo, com cerca de 260 milhões de falantes nativos em nove países. Brasil, Portugal, Angola, Moçambique e Cabo Verde são alguns dos países lusófonos espalhados por quatro continentes. A Comunidade dos Países de Língua Portuguesa (CPLP) promove a cooperação entre as nações que têm o português como língua oficial. O português brasileiro e o português europeu têm diferenças significativas de pronúncia, vocabulário e gramática. Luís de Camões, com Os Lusíadas, criou o monumento máximo da literatura portuguesa no século XVI. A língua portuguesa tem uma rica tradição literária, com prêmios Nobel como José Saramago e José Saramago.',
            'date' => '2026-04-02',
        ],
        'pt-03' => [
            'title' => 'O Brasil: diversidade e cultura',
            'body' => 'O Brasil é o maior país da América Latina e o quinto maior do mundo em área e população. A Floresta Amazônica cobre mais de 60 por cento do território brasileiro e abriga a maior biodiversidade do planeta. O carnaval brasileiro é uma das maiores festas do mundo, com desfiles de escolas de samba, música e dança. A capoeira é uma arte marcial brasileira que combina movimentos de dança, acrobacia e música. A culinária brasileira é diversa e influenciada por culturas indígenas, africanas e europeias, com pratos como feijoada e churrasco. O futebol é paixão nacional e o Brasil é o único país a ter participado de todas as edições da Copa do Mundo.',
            'date' => '2026-04-03',
        ],
        'pt-04' => [
            'title' => 'Lisboa: história e modernidade',
            'body' => 'Lisboa é uma das cidades mais antigas da Europa, com uma história que remonta a mais de 3000 anos. O Castelo de São Jorge domina a paisagem da cidade e oferece uma vista panorâmica de Lisboa e do Rio Tejo. O Mosteiro dos Jerónimos em Belém é um magnífico exemplo da arquitetura manuelina e Património da Humanidade da UNESCO. O bairro de Alfama é o mais antigo de Lisboa e conserva a tradição do fado, música portuguesa de profunda melancolia. O Marquês de Pombal reconstruiu grande parte de Lisboa após o terrível terremoto de 1755 segundo princípios iluministas. Lisboa tornou-se um destino turístico muito procurado, conhecido por seu clima ameno, gastronomia e vida noturna vibrante.',
            'date' => '2026-04-04',
        ],
        'pt-05' => [
            'title' => 'A música portuguesa: fado e tradição',
            'body' => 'O fado é o gênero musical mais representativo de Portugal, reconhecido pela UNESCO como Património Cultural Imaterial da Humanidade. As origens do fado remontam ao início do século XIX em Lisboa, com influências africanas, brasileiras e mouriscas. Amália Rodrigues é considerada a rainha do fado e foi responsável pela internacionalização deste gênero musical. O fado de Coimbra é diferente do lisboeta: mais lírico e geralmente cantado por homens com voz mais grave. Guitarras portuguesas, com a sua forma característica de pêra e afinação especial, são instrumentos únicos do fado. Mariza, Ana Moura e Camané são alguns dos grandes nomes do fado contemporâneo que levam esta música pelo mundo.',
            'date' => '2026-04-05',
        ],
    ],

    // Romanian - history/Balkans theme
    'ro' => [
        'ro-01' => [
            'title' => 'Istoria și tradițiile României',
            'body' => 'România are o istorie bogată care se întinde pe mii de ani, cu rădăcini în civilizațiile dacice și romane. Regele dac Decebal a opus rezistență eroică împotriva cuceririi romane conduse de împăratul Traian în secolele I și II. Dacia a fost o provincie romană între 106 și 271 d.Hr., perioadă care a lăsat amprente profunde asupra limbii și culturii românești. Ștefan cel Mare, domnitorul Moldovei, a apărat creștinătatea împotriva invaziei otomane și a construit zeci de mânăstiri. Mihai Viteazul a realizat prima unire a Țărilor Române în 1600, devenind un simbol al unității naționale. Marea Unire din 1918 a reunificat Transilvania, Basarabia și Bucovina cu Regatul României.',
            'date' => '2026-04-01',
        ],
        'ro-02' => [
            'title' => 'Mânăstirile și patrimoniul cultural din România',
            'body' => 'România deține un patrimoniu cultural excepțional, cu numeroase mânăstiri și situri înscrise pe lista UNESCO. Mânăstirile pictate din Bucovina, cum ar fi Voroneț, Humor și Sucevița, sunt celebre pentru frescele lor exterioare cu culori vii. Voronetul, supranumit Capelina Sixtina a Orientului, are celebrul albastru de Voroneț unic în lume. Sarmizegetusa Regia a fost capitala regatului dac și rămâne un simbol al civilizației dacice. Delta Dunării este una dintre cele mai mari și mai bine conservate delte fluviale din lume, cu o biodiversitate extraordinară. Castelul Bran, asociat cu legenda lui Dracula, este cel mai vizitat monument din România.',
            'date' => '2026-04-02',
        ],
        'ro-03' => [
            'title' => 'Limba română și literatura',
            'body' => 'Limba română este singura limbă romanică vorbită în Europa de Est și se distinge prin păstrarea multor trăsături latine. Mihai Eminescu, poetul național al României, a îmbogățit literatura română cu versuri de o profundă originalitate și sensibilitate. Ion Creangă, prin Amintiri din copilărie, a imortalizat viața rurală moldovenească din secolul al XIX-lea. Lucian Blaga a îmbinat filozofia cu poezia, creând opere de mare profunzime spirituală. Eugène Ionescu, fondatorul teatrului absurdului, s-a afirmat pe scena pariziană cu piese ca Cântăreața cheală și Rinocerii. Mircea Eliade a dobândit recunoaștere internațională prin lucrările sale de filozofie a religiei și istoria religiilor.',
            'date' => '2026-04-03',
        ],
        'ro-04' => [
            'title' => 'Gastronomia românească',
            'body' => 'Bucătăria românească reflectă istoria și influențele multiple ale acestei regiuni din inima Europei. Sarmale, rulouri de varză umplute cu carne și orez, sunt mâncarea tradițională de sărbătoare a românilor. Mămăliga este porumb fiert și este echivalentul românesc al pâinii, consumat cu brânză, smântână sau cârnați. Ciorbele, supe acrite cu borș sau lămâie, sunt un element esențial al mesei românești. Mici, cârnăciori scurți din carne de vită și porc cu condimente, sunt preparatul preferat la grătar al românilor. Cozonacul este o pâine dulce cu umplutură de nucă sau mac, tradițional pregătită la Paști și Crăciun.',
            'date' => '2026-04-04',
        ],
        'ro-05' => [
            'title' => 'Muzica și folclorul românesc',
            'body' => 'Folclorul românesc este o comoară culturală de o varietate și bogăție remarcabilă, cu tradiții diferite în fiecare regiune. Muzica populară românească este caracterizată prin ritmuri vii, melodii bogate și instrumente tradiționale ca fluierul, cobza și naiul. Nai-ul, instrumentul lui Gheorghe Zamfir, a cucerit inimile publicului din întreaga lume cu sonoritățile sale unice. Portul popular românesc variază de la o regiune la alta, fiecare zonă etnografică având costume tradiționale distincte. Hora este un dans tradițional în cerc care se dansează la nunți, sărbători și diferite celebrări comunitare. George Enescu este cel mai mare compozitor român, al cărui Poem român și suite orchestrale sunt apreciate pe scene internaționale.',
            'date' => '2026-04-05',
        ],
    ],

    // Russian - literature/culture theme
    'ru' => [
        'ru-01' => [
            'title' => 'Русская литература XIX века',
            'body' => 'Русская литература XIX века является одной из величайших в мировой культуре. Александр Сергеевич Пушкин считается основоположником современного русского литературного языка и автором бессмертных произведений «Евгений Онегин», «Капитанская дочка» и множества стихотворений. Лев Николаевич Толстой создал монументальные романы «Война и мир» и «Анна Каренина», исследуя глубины человеческой души. Фёдор Михайлович Достоевский потрясал читателей своими психологическими романами «Преступление и наказание», «Братья Карамазовы» и «Идиот». Антон Павлович Чехов мастерски изображал повседневную жизнь в рассказах и пьесах, таких как «Вишнёвый сад» и «Чайка».',
            'date' => '2026-04-01',
        ],
        'ru-02' => [
            'title' => 'История и культура России',
            'body' => 'Россия — крупнейшая страна в мире с богатой многовековой историей. Киевская Русь, основанная в IX веке, стала колыбелью трёх восточнославянских народов. Монгольское нашествие в XIII веке нанесло огромный ущерб, но Русь возродилась и укрепилась. Пётр Великий провёл масштабные реформы в начале XVIII века, открыв Россию для западного влияния. Екатерина II правила в эпоху Просвещения, расширив территорию России и поощряя развитие науки и культуры. Революция 1917 года привела к созданию Советского Союза, изменившего ход мировой истории.',
            'date' => '2026-04-02',
        ],
        'ru-03' => [
            'title' => 'Русская музыка и балет',
            'body' => 'Русская классическая музыка занимает одно из центральных мест в мировом музыкальном наследии. Пётр Ильич Чайковский создал шедевры балетной музыки — «Лебединое озеро», «Спящая красавица» и «Щелкунчик». Михаил Глинка заложил основы национальной русской музыки, создав первые русские оперы «Жизнь за царя» и «Руслан и Людмила». Большой театр в Москве является одним из самых известных оперных и балетных театров в мире. Дмитрий Шостакович создавал музыку, отражающую трагические события советской эпохи. Русский балет, рождённый в Императорских театрах, оказал огромное влияние на развитие балетного искусства во всём мире.',
            'date' => '2026-04-03',
        ],
        'ru-04' => [
            'title' => 'Наука и технологии в России',
            'body' => 'Россия имеет богатые традиции в развитии науки и техники, внёсших значительный вклад в мировой прогресс. Дмитрий Менделеев создал Периодическую таблицу химических элементов в 1869 году, систематизировав все известные элементы. Константин Циолковский заложил теоретические основы космонавтики, предвидев возможность полётов в космос. Советский Союз запустил первый искусственный спутник Земли «Спутник-1» в 1957 году, ознаменовав начало космической эры. Юрий Гагарин стал первым человеком, совершившим космический полёт 12 апреля 1961 года. Россия продолжает активно участвовать в международных космических программах и развивать собственные технологии.',
            'date' => '2026-04-04',
        ],
        'ru-05' => [
            'title' => 'Русская кухня и традиции',
            'body' => 'Русская кухня отличается своей сытностью и разнообразием, отражая суровые климатические условия страны. Борщ — традиционный суп со свёклой и капустой — является символом русской и украинской кухни, его рецептов существует множество. Пельмени — пельмени с мясной начинкой — это популярное блюдо, которое едят по всей России с различными добавками. Блины, тонкие блинчики, традиционно готовятся на Масленицу и подаются со сметаной, икрой или джемом. Русские чайные традиции предполагают использование самовара для приготовления крепкого чая, который пьют с сахаром и сладостями. Квас — традиционный русский напиток из ферментированного хлеба — популярен в жаркое летнее время.',
            'date' => '2026-04-05',
        ],
    ],

    // Spanish - literature/culture theme
    'es' => [
        'es-01' => [
            'title' => 'La literatura española del Siglo de Oro',
            'body' => 'El Siglo de Oro español fue un período de extraordinaria floración cultural que abarcó los siglos XVI y XVII. Miguel de Cervantes escribió El ingenioso hidalgo Don Quijote de la Mancha, considerada la primera novela moderna y una de las obras más importantes de la literatura universal. Lope de Vega renovó completamente el teatro español con más de trescientas obras dramáticas que revolucionaron la escena. Francisco de Quevedo cultivó la poesía conceptista con versos llenos de agudeza e ingenio verbal. Luis de Góngora representó el culteranismo con un lenguaje exquisitamente elaborado y metáforas de gran belleza. Santa Teresa de Ávila y San Juan de la Cruz elevaron la mística española a las cimas de la poesía universal.',
            'date' => '2026-04-01',
        ],
        'es-02' => [
            'title' => 'La cocina española y sus regiones',
            'body' => 'La gastronomía española es una de las más reconocidas internacionalmente, con una diversidad regional que refleja la riqueza cultural del país. La paella valenciana, con arroz, pollo, conejo y judías verdes, es quizás el plato más internacional de España. El gazpacho andaluz, una sopa fría de tomate, pimiento y pepino, es perfecto para el caluroso verano mediterráneo. Las tapas son una institución social española, pequeñas raciones de comida que se comparten en bares y restaurantes. El jamón ibérico de bellota, producto de la dehesa extremeña, está considerado uno de los mejores embutidos del mundo. La tortilla española de patata y el pan con tomate catalán son platos cotidianos que encierran toda la sabiduría culinaria del país.',
            'date' => '2026-04-02',
        ],
        'es-03' => [
            'title' => 'El arte español: de Goya a Picasso',
            'body' => 'España ha dado algunos de los más grandes artistas de la historia occidental, desde los maestros barrocos hasta las vanguardias del siglo XX. Diego Velázquez, pintor de cámara de Felipe IV, creó obras maestras como Las Meninas, que revolucionó la concepción de la pintura. Francisco de Goya retrató con lucidez oscura la España de su tiempo, desde sus tapices para la corte hasta los Desastres de la guerra. Pablo Picasso, junto a Georges Braque, fundó el cubismo y transformó radicalmente el arte del siglo XX con obras como el Guernica. Salvador Dalí fue el máximo representante del surrealismo con obras como La persistencia de la memoria. El Museo del Prado en Madrid es uno de los mejores museos de arte del mundo, con obras de El Greco, Tiziano y Rubens.',
            'date' => '2026-04-03',
        ],
        'es-04' => [
            'title' => 'La música española: flamenco y más',
            'body' => 'La música española es tan diversa como las regiones del país, con el flamenco como expresión más internacional. El flamenco, originario del sur de España, es una fusión de estilos musicales de influencias gitanas, moriscas y andaluzas. La guitarra flamenca, el cante jondo y el baile forman la trilogía artística del flamenco, declarado Patrimonio Cultural Inmaterial por la UNESCO. Paco de Lucía revolucionó la guitarra flamenca al incorporar elementos del jazz y de otras tradiciones musicales del mundo. Andrés Segovia elevó la guitarra clásica al rango de instrumento de concierto y es considerado el padre de la guitarra moderna. Isaac Albéniz y Enrique Granados compusieron para piano obras de gran inspiración hispánica que alcanzaron fama mundial.',
            'date' => '2026-04-04',
        ],
        'es-05' => [
            'title' => 'Hispanoamérica: lengua y diversidad cultural',
            'body' => 'El español es hablado por más de 500 millones de personas en el mundo, siendo la segunda lengua más hablada por número de nativos. Hispanoamérica comprende 19 países donde el español es idioma oficial, cada uno con su propia identidad cultural y literaria. El boom latinoamericano de los años 60 y 70 situó la literatura hispanoamericana en el primer plano mundial con autores como Gabriel García Márquez, Mario Vargas Llosa y Julio Cortázar. Gabriel García Márquez, colombiano, ganó el Premio Nobel de Literatura en 1982 por obras como Cien años de soledad, cumbre del realismo mágico. La diversidad geográfica y cultural de los países hispanohablantes se refleja en su música, desde el tango argentino hasta la salsa colombiana y el reggaetón puertorriqueño.',
            'date' => '2026-04-05',
        ],
    ],

    // Swedish - nature/society theme
    'sv' => [
        'sv-01' => [
            'title' => 'Svensk natur och landskap',
            'body' => 'Sverige är ett avlångt land med en stor variation i landskap och natur. Från de flacka slätterna i Skåne i söder till de höga fjällen i Lapplandsfjällen i norr sträcker sig landet över tio breddgrader. Allemansrätten ger alla rätten att vistas i naturen, plocka bär och svamp, och röra sig fritt även på privat mark. Sverige har ett av Europas tätaste skogslandskap, med barrskog som dominerar och naturreservat som skyddar den biologiska mångfalden. De svenska kustlinjerna erbjuder skärgårdar av unik skönhet, med Stockholm skärgård som en av de mest välbesökta. Isälvar och fjällen i Norra Sverige lockar till sig äventyrsentusiaster och naturälskare från hela världen.',
            'date' => '2026-04-01',
        ],
        'sv-02' => [
            'title' => 'Svenska uppfinningar och innovationer',
            'body' => 'Sverige är ett litet land med en anmärkningsvärd historia av tekniska uppfinningar och innovationer. Alfred Nobel uppfann dynamiten 1867 och hans testamente ledde till inrättandet av Nobelpriset. Gustaf Dalén vann Nobelpriset i fysik 1912 för sina uppfinningar som automatiserade fyrlyktors drift. Anders Celsius skapade Celsiusskalan för temperaturmätning som används globalt. Volvo, Saab och Scania är svenska biltillverkare kända för säkerhet och hållbarhet. Ikea revolutionerade möbelbranschen med sina platta paket och moderna skandinaviska design. Spotify, Skype och Mojang är moderna svenska teknikföretag som har förändrat digital underhållning och kommunikation.',
            'date' => '2026-04-02',
        ],
        'sv-03' => [
            'title' => 'Svensk matkultur och traditioner',
            'body' => 'Den svenska matkulturen präglas av enkla råvaror som behandlas med omsorg och tradition. Smörgåsbord är en svensk tradition som är känd världen över och innebär ett rikt upplägg av kalla och varma rätter. Köttbullar med potatismos och lingonsylt är en klassisk svensk husmanskost som fått internationell spridning bland annat via Ikea. Midsommarfirande innefattar alltid smörgåsbord med sill, potatis och jordgubbar som traditionella rätter. Surströmming, saltad och fermenterad strömming, är en delikatess med välkänd stark doft som delas upp i åsikter. Semla, en kardemummabulle fylld med mandelmassa och grädde, äts traditionellt på fettisdagen men numera hela vintern.',
            'date' => '2026-04-03',
        ],
        'sv-04' => [
            'title' => 'Svensk musik och pop',
            'body' => 'Sverige är en musikexportör av internationell klass med en musiktradition som sträcker sig från folkmusik till modern pop. ABBA är det mest framgångsrika svenska bandet genom tiderna med låtar som Dancing Queen och Mamma Mia som spelas runt om i världen. Avicii bidrog till att forma den globala EDM-scenen med sina hits Wake Me Up och Levels. Björn Borg och Robyn är andra svenska artister som nått internationell framgång inom popmusik. Sverige producerar regelbundet topplaceringar i Eurovision Song Contest och har vunnit tävlingen ett flertal gånger. Det svenska musikundret förklaras delvis av den höga musikpedagogiska standarden och kulturskolan som ger barn möjlighet till musikundervisning.',
            'date' => '2026-04-04',
        ],
        'sv-05' => [
            'title' => 'Det svenska samhällssystemet',
            'body' => 'Sverige har ett välutbyggt välfärdssystem som garanterar medborgarna tillgång till utbildning, sjukvård och social trygghet. Den svenska modellen kombinerar ett fritt näringsliv med starka fackföreningar och generösa sociala skyddsnät. Föräldraledigheten i Sverige är bland de mest generösa i världen, med möjlighet att dela 480 dagar mellan föräldrarna. Barnbidrag och subventionerad förskola bidrar till en av de högsta förvärvsfrekvenserna bland föräldrar i världen. Den kommunala utjämningen säkerställer att alla kommuner har resurser att erbjuda en god offentlig service oavsett skattebas. Sverige har en av de lägsta korruptionsnivåerna i världen och en hög tillit till myndigheter och institutioner.',
            'date' => '2026-04-05',
        ],
    ],

    // Turkish - history/culture theme
    'tr' => [
        'tr-01' => [
            'title' => 'Osmanlı İmparatorluğu\'nun Tarihi',
            'body' => 'Osmanlı İmparatorluğu, dünya tarihinin en uzun ömürlü ve en geniş coğrafyaya yayılan imparatorluklarından biridir. 1299 yılında Osman Bey tarafından kurulan devlet, Yavuz Sultan Selim ve Kanuni Sultan Süleyman dönemlerinde en geniş sınırlarına ulaşmıştır. İstanbul\'un 1453\'te fethi, Orta Çağ\'ın sona erişinin simgesi olarak tarihe geçmiştir. Osmanlı yönetimi altında farklı din ve etnik kökenden insanlar bir arada yaşamış, devlet genellikle dinî ve kültürel çeşitliliğe saygı göstermiştir. Osmanlı mimarisi, Mimar Sinan gibi dehaların eliyle camiiler, hanlar ve köprüler gibi kalıcı eserler bırakmıştır. 19. yüzyılda başlayan çöküş süreciyle birlikte imparatorluk topraklarını yitirmiş ve 1922\'de Türkiye Cumhuriyeti kurulmuştur.',
            'date' => '2026-04-01',
        ],
        'tr-02' => [
            'title' => 'Türk mutfağı ve yemek kültürü',
            'body' => 'Türk mutfağı dünya genelinde en zengin ve çeşitli mutfaklar arasında yer alır. Kebap, Türk mutfağının en bilinen yemeği olup çeşitli biçimlerde hazırlanır; Adana kebabı, şiş kebap ve döner kebap en popüler olanlar arasındadır. Baklava, ince katmanlı hamur, tereyağı, fıstık veya cevizle yapılan tatlı, Osmanlı mutfağının mücevheri olarak tanınır. Türk çayı, ülkenin her köşesinde tüketilen çay, Türk kültürünün ayrılmaz bir parçasıdır. Mercimek çorbası, nohutlu pilav ve börek gibi yemekler günlük Türk mutfağının temel taşlarıdır. Türk kahvesi, 16. yüzyıldan beri içilmekte olup İnsanlığın Somut Olmayan Kültürel Mirası listesine alınmıştır.',
            'date' => '2026-04-02',
        ],
        'tr-03' => [
            'title' => 'Türk dili ve edebiyatı',
            'body' => 'Türk dili, Ural-Altay dil ailesine mensup bir dil olup dünyada 80 ila 90 milyon kişi tarafından konuşulmaktadır. Türkçe, Türkiye ve Kıbrıs\'ın resmi dili olup Balkanlar, Orta Asya ve Orta Doğu\'da azınlıklar tarafından da kullanılmaktadır. Divan edebiyatı, Osmanlı döneminde Farsça ve Arapça etkisiyle şekillenmiş bir edebiyat geleneğidir. Yunus Emre, 13. yüzyılda yaşayan mistik bir şair olup şiirleriyle Türk halk edebiyatının temelini atmıştır. Orhan Pamuk, 2006 yılında Nobel Edebiyat Ödülü\'nü kazanan Türk romancısıdır. Yaşar Kemal, İnce Memed romanıyla dünya edebiyatına kazandırılan önemli bir Türk yazarıdır.',
            'date' => '2026-04-03',
        ],
        'tr-04' => [
            'title' => 'İstanbul: tarihin ve modernliğin buluştuğu şehir',
            'body' => 'İstanbul, iki kıtada yer alan dünyanın tek şehridir ve Avrupa ile Asya arasındaki boğazda kurulmuştur. Tarihi Yarımada, 2500 yıllık kesintisiz yerleşim tarihiyle pek çok medeniyete ev sahipliği yapmıştır. Ayasofya, 537 yılında inşa edilen ve sonradan camiye dönüştürülen olağanüstü bir mimari şaheserdir. Topkapı Sarayı, yüzyıllar boyunca Osmanlı sultanlarına ev sahipliği yapmış ve bugün müzeye dönüştürülmüştür. Kapalıçarşı, dünyanın en büyük ve en eski kapalı çarşılarından biri olup 5000\'den fazla dükkânı barındırmaktadır. Boğaz köprüleri ve gökdelenler, şehrin yeni çehresi olarak tarihi panoramanın yanında yükselmektedir.',
            'date' => '2026-04-04',
        ],
        'tr-05' => [
            'title' => 'Türk müziği ve halk dansları',
            'body' => 'Türk müziği, binlerce yıllık köklü bir geleneği barındıran zengin ve çeşitli bir müzik kültürüne sahiptir. Halk müziği, Anadolu\'nun farklı bölgelerinde kendine özgü ritimler, ezgiler ve enstrümanlar geliştirmiştir. Bağlama, Türk halk müziğinin en önemli çalgısıdır ve asak ile saz olarak da bilinmektedir. Zeybek, halay ve horon, Türkiye\'nin farklı bölgelerine özgü geleneksel dans türleridir. Klasik Türk sanat müziği, makamlar ve usullere dayanan sofistike bir müzik anlayışını yansıtır. Tarkan, Sertap Erener ve Sezen Aksu gibi sanatçılar modern Türk pop müziğini uluslararası arenada temsil etmiştir.',
            'date' => '2026-04-05',
        ],
    ],
];

$total = 0;

echo 'Generating multilingual concordance corpus...' . PHP_EOL;

foreach ($corpus as $lang => $pages) {
    foreach ($pages as $filename => $page) {
        $date = $page['date'];
        $html = "<!DOCTYPE html>\n<html lang=\"{$lang}\">\n<head>\n<meta charset=\"utf-8\">\n";
        $html .= '<title>' . htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') . "</title>\n";
        $html .= "</head>\n<body data-pagefind-body>\n";
        $html .= '<h1>' . htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') . "</h1>\n";
        $html .= '<p>' . htmlspecialchars($page['body'], ENT_QUOTES, 'UTF-8') . "</p>\n";
        $html .= "<p data-pagefind-meta=\"date:{$date}\" hidden></p>\n";
        $html .= "<p data-pagefind-filter=\"language:{$lang}\" hidden></p>\n";
        $html .= "</body>\n</html>\n";

        file_put_contents("{$dir}/{$filename}.html", $html);
        $total++;
    }
}

echo "Done. Generated {$total} files across " . count($corpus) . ' languages.' . PHP_EOL;
