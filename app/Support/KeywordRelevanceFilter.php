<?php

namespace App\Support;

class KeywordRelevanceFilter
{
    /**
     * Stop words and generic prepositions that have zero standalone entity meaning.
     */
    protected static array $stopWords = [
        'آراء', 'رأي', 'اراء', 'حول', 'عن', 'في', 'تجربة', 'تجارب', 'أخبار', 'اخبار', 'خبر',
        'رصد', 'تفاصيل', 'قصة', 'حقيقة', 'موضوع', 'استطلاع', 'بشأن', 'ضد', 'مع', 'على',
        'من', 'إلى', 'الى', 'مراجعة', 'تقييم', 'شرح', 'نتائج', 'أحدث', 'احدث', 'عاجل',
        'هذا', 'هذه', 'تم', 'كان', 'كانت', 'كل', 'بعد', 'قبل', 'خلال', 'ضمن', 'بين'
    ];

    /**
     * Generic titles, honorifics, and organizational prefixes.
     * NEVER allowed to act as standalone matches.
     */
    protected static array $genericTitlesAndOrgs = [
        'رئيس', 'الرئيس', 'تنفيذي', 'التنفيذي', 'مدير', 'المدير', 'دكتور', 'الدكتور', 'د',
        'مهندس', 'المهندس', 'م', 'أستاذ', 'الأستاذ', 'أ', 'شيخ', 'الشيخ', 'أمير', 'الأمير',
        'سمو', 'معالي', 'سعادة', 'وزير', 'الوزير', 'نائب', 'النائب', 'وكيل', 'الوكيل',
        'مسؤول', 'المسؤول', 'عضو', 'العضو', 'محافظ', 'المحافظ', 'أمين', 'الأمين',
        'مجمع', 'المجمع', 'تجمع', 'التجمع', 'مركز', 'المركز', 'هيئة', 'الهيئة',
        'وزارة', 'الوزارة', 'إدارة', 'الإدارة', 'مستشفى', 'المستشفى', 'قطاع', 'القطاع',
        'شركة', 'الشركة', 'مؤسسة', 'المؤسسة', 'وكالة', 'الوكالة', 'مكتب', 'المكتب',
        'جامعة', 'الجامعة', 'كلية', 'الكلية', 'مدرسة', 'المدرسة', 'صحيفة', 'الصحيفة',
        'جريدة', 'الجريدة', 'قناة', 'القناة', 'جمعية', 'الجمعية', 'منظمة', 'المنظمة',
        'مجلس', 'المجلس', 'لجنة', 'اللجنة', 'فرع', 'الفرع'
    ];

    /**
     * Generic descriptive adjectives.
     */
    protected static array $genericAdjectives = [
        'صحي', 'صحيّ', 'الصحي', 'الصحية', 'طبي', 'طبيّ', 'الطبي', 'الطبية',
        'عام', 'العام', 'العامة', 'خاص', 'الخاص', 'الخاصة', 'رسمي', 'الرسمي', 'الرسمية',
        'جديد', 'الجديد', 'الجديدة', 'سابق', 'السابق', 'السابقة'
    ];

    /**
     * Common cities and regions that should not match as standalone entity matches
     * when part of a compound person/organization query.
     */
    protected static array $commonRegions = [
        'تبوك', 'الرياض', 'جدة', 'القصيم', 'مكة', 'المدينة', 'الدمام', 'الخبر',
        'عسير', 'جازان', 'نجران', 'حائل', 'الجوف', 'الباحة', 'السعودية', 'المملكة',
        'مصر', 'القاهرة', 'الإسكندرية', 'الكويت', 'الإمارات', 'دبي', 'أبوظبي', 'قطر', 'البحرين', 'عمان'
    ];

    /**
     * Normalize Arabic text for uniform matching.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // Alef variants to bare Alef
        $text = preg_replace('/[أإآآ]/u', 'ا', $text);
        // Yaa to standard Yaa
        $text = str_replace('ى', 'ي', $text);
        // Taa Marbuta to Haa
        $text = str_replace('ة', 'ه', $text);
        // Remove Tatweel / Kashida
        $text = str_replace('ـ', '', $text);
        // Remove diacritics (Harakat)
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        // Replace non-alphanumeric Arabic/English characters with spaces
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        // Collapse spaces
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Determine if a word or short token is generic and forbidden as a standalone match.
     */
    public static function isGenericToken(string $token): bool
    {
        $norm = self::normalize($token);
        if (mb_strlen($norm) < 3) {
            return true;
        }

        $allGenerics = array_merge(
            self::$stopWords,
            self::$genericTitlesAndOrgs,
            self::$genericAdjectives,
            ['بن', 'ابن', 'عبد', 'الله', 'عبدالله', 'ال']
        );

        $normalizedGenerics = array_map([self::class, 'normalize'], $allGenerics);
        return in_array($norm, $normalizedGenerics, true);
    }

    /**
     * Extract validation anchors from a keyword string.
     * Returns multi-word distinct phrases and required pairs.
     */
    public static function extractValidationAnchors(string $keyword, array $allKeywords = []): array
    {
        $clean = trim($keyword, " \t\n\r\0\x0B\"'");
        $clean = preg_replace('/^#+/u', '', $clean);
        $clean = str_replace('_', ' ', $clean);

        $phrases = [];
        $pairs = [];

        // 1. Explicit conjunction / AND operators: "+" or "AND" or "&"
        if (preg_match('/\s*(?:\+|AND|&)\s+/ui', $clean)) {
            $andParts = preg_split('/\s*(?:\+|AND|&)\s+/ui', $clean);
            $andParts = array_values(array_filter(array_map('trim', $andParts)));
            if (count($andParts) >= 2) {
                $pairs[] = $andParts;
                $phrases[] = implode(' ', $andParts);
            }
        }

        // If explicit delimiter is present (e.g. "term1 | term2" or comma)
        if (preg_match('/\s*[\/|,]\s+/u', $clean)) {
            $parts = preg_split('/\s*[\/|,]\s+/u', $clean);
            foreach ($parts as $p) {
                $p = trim($p);
                if (mb_strlen($p) >= 3 && !self::isGenericToken($p)) {
                    $phrases[] = $p;
                }
            }
        }

        // 2. Person Name Detection:
        // Pattern A: [Honorific]? [First] [بن|ابن] [Father] ([بن|ابن] [Grandfather])? [Family]
        // E.g.: "متعب بن عبدالله الحربي", "متعب ابن عبدالله الحربي", "متعب بن عبد الله الحربي"
        $personRegex = '/(?:^|\s)(?:دكتور|الدكتور|الأستاذ|الاستاذ|أستاذ|استاذ|الشيخ|شيخ|معالي|سعادة|المهندس|مهندس)?\s*([\p{L}]+)\s+(?:بن|ابن)\s+((?:عبد\s+الله|عبدالله|[\p{L}]+))(?:\s+(?:بن|ابن)\s+[\p{L}]+)?\s+([\p{L}]+)/u';

        // Pattern B: 3 words with Abd-Allah father without bin: "متعب عبدالله الحربي" or "متعب عبد الله الحربي"
        $triPersonRegex = '/(?:^|\s)(?:دكتور|الدكتور|الأستاذ|الاستاذ|أستاذ|استاذ|الشيخ|شيخ|معالي|سعادة|المهندس|مهندس)?\s*([\p{L}]+)\s+((?:عبد\s+الله|عبدالله))\s+([\p{L}]+)/u';

        // Pattern C: 2-word person name followed by context/remainder: "متعب الحربي تجمع تبوك الصحي"
        $biPersonRegex = '/^(?:دكتور|الدكتور|الأستاذ|الاستاذ|أستاذ|استاذ|الشيخ|شيخ|معالي|سعادة|المهندس|مهندس)?\s*([\p{L}]+)\s+([\p{L}]+)(?=\s+(?:رئيس|الرئيس|مدير|المدير|تنفيذي|التنفيذي|تجمع|التجمع|مجمع|المجمع|مستشفى|مركز|قطاع|صحة|وزارة|تبوك)\b|$)/u';

        if (preg_match($personRegex, $clean, $m) || preg_match($triPersonRegex, $clean, $m) || (preg_match($biPersonRegex, $clean, $m) && !str_contains($clean, 'بن') && !str_contains($clean, 'ابن'))) {
            $matchedName = trim($m[0]);
            $firstName = trim($m[1]);
            $father = isset($m[3]) ? trim($m[2]) : '';
            $family = isset($m[3]) ? trim($m[3]) : trim($m[2]);

            if (!self::isGenericToken($firstName) && !self::isGenericToken($family)) {
                $shortName = "{$firstName} {$family}";
                $remainder = trim(str_replace($matchedName, '', $clean));
                $hasRemainder = !empty($remainder);
                $isPatronymic = !empty($father);

                // Full distinctive name with father (e.g. "متعب بن عبدالله الحربي") distinguishes from other people with same family name
                if ($isPatronymic) {
                    $phrases[] = "{$firstName} بن {$father} {$family}";
                    $phrases[] = "{$firstName} ابن {$father} {$family}";

                    if (mb_stripos($father, 'عبد') !== false) {
                        $altFather = str_contains($father, ' ') ? str_replace(' ', '', $father) : preg_replace('/^عبد/u', 'عبد ', $father);
                        $phrases[] = "{$firstName} بن {$altFather} {$family}";
                        $phrases[] = "{$firstName} ابن {$altFather} {$family}";
                    }
                }

                // If explicit organization/role context is present in the remainder
                if ($hasRemainder) {
                    $orgPhrases = self::extractOrgPhrases($remainder);
                    foreach ($orgPhrases as $op) {
                        $phrases[] = "{$shortName} {$op}";
                        $phrases[] = "{$op} {$shortName}";
                        $pairs[] = [$firstName, $op];
                        $pairs[] = [$family, $op];
                        $pairs[] = [$shortName, $op];
                    }

                    $remWords = array_values(array_filter(explode(' ', $remainder)));
                    foreach ($remWords as $rw) {
                        $normRw = self::normalize($rw);
                        if (!self::isGenericToken($rw) && mb_strlen($normRw) >= 3) {
                            $pairs[] = [$firstName, $rw];
                            $pairs[] = [$family, $rw];
                            $pairs[] = [$shortName, $rw];
                        }
                    }
                } else {
                    // When NO remainder is present in this keyword:
                    // Extract domain context from other keywords in the campaign (e.g., "مجمع تبوك الطبي")
                    $domainTokens = self::extractDomainContextTokens($allKeywords, $clean);

                    if (!empty($domainTokens)) {
                        foreach ($domainTokens as $dt) {
                            $phrases[] = "{$shortName} {$dt}";
                            $pairs[] = [$shortName, $dt];
                        }
                    }

                    // Standard leadership and executive anchors to prevent homonym pollution
                    $leadershipRoles = ['تنفيذي', 'الرئيس التنفيذي', 'رئيس', 'مدير', 'تكليف', 'تعيين'];
                    foreach ($leadershipRoles as $lr) {
                        $pairs[] = [$shortName, $lr];
                    }

                    // If NOT patronymic and no external domain tokens found, allow bare short name as fallback
                    if (!$isPatronymic && empty($domainTokens)) {
                        $phrases[] = $shortName;
                        $pairs[] = [$firstName, $family];
                    }
                }
            }
        } else {
            // General multi-word phrase parsing
            $words = array_values(array_filter(explode(' ', $clean)));
            if (count($words) >= 2) {
                if (preg_match('/(?:دكتور|الدكتور|الأستاذ|استاذ|الشيخ|شيخ|معالي|سعادة|المهندس|مهندس)\s+([\p{L}]+)\s+([\p{L}]+)/u', $clean, $pm)) {
                    $phrases[] = "{$pm[1]} {$pm[2]}";
                    $pairs[] = [$pm[1], $pm[2]];
                }

                $orgPhrases = self::extractOrgPhrases($clean);
                foreach ($orgPhrases as $op) {
                    $phrases[] = $op;
                }

                for ($i = 0; $i < count($words) - 1; $i++) {
                    $w1 = $words[$i];
                    $w2 = $words[$i + 1];
                    if (!self::isGenericToken($w1) && !self::isGenericToken($w2)) {
                        $phrases[] = "{$w1} {$w2}";
                    }
                }
            }
        }

        // Deduplicate and filter out single generic words and standalone common regions
        $cleanPhrases = [];
        foreach ($phrases as $p) {
            $p = trim($p);
            $words = array_values(array_filter(explode(' ', $p)));
            if (count($words) === 1 && self::isCommonRegion($p)) {
                continue;
            }
            if (mb_strlen(self::normalize($p)) >= 3 && !self::isGenericToken($p)) {
                $cleanPhrases[] = $p;
            }
        }

        return [
            'phrases' => array_values(array_unique($cleanPhrases)),
            'pairs' => $pairs,
        ];
    }

    /**
     * Check if a token is a common city or region name.
     */
    public static function isCommonRegion(string $token): bool
    {
        $norm = self::normalize($token);
        $normalizedRegions = array_map([self::class, 'normalize'], self::$commonRegions);
        return in_array($norm, $normalizedRegions, true);
    }

    /**
     * Extract contextual domain tokens from other keywords to anchor person entities.
     */
    public static function extractDomainContextTokens(array $allKeywords, ?string $excludeKeyword = null): array
    {
        $tokens = [];
        $normExclude = $excludeKeyword ? self::normalize($excludeKeyword) : null;

        foreach ($allKeywords as $kw) {
            $kw = trim($kw);
            if (empty($kw)) continue;

            $normKw = self::normalize($kw);
            if ($normExclude && $normKw === $normExclude) {
                continue;
            }

            // Extract org phrases (e.g., "مجمع تبوك الطبي" -> "تجمع تبوك الصحي", "تبوك الطبي", etc.)
            $orgPhrases = self::extractOrgPhrases($kw);
            foreach ($orgPhrases as $op) {
                if (mb_strlen(self::normalize($op)) >= 4) {
                    $tokens[] = $op;
                }
            }

            // Extract individual non-generic words
            $words = array_values(array_filter(explode(' ', $kw)));
            foreach ($words as $w) {
                $normW = self::normalize($w);
                if (mb_strlen($normW) >= 3 && !self::isGenericToken($w)) {
                    $tokens[] = $w;
                }
            }
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    /**
     * Extract specific organizational phrases (e.g., "مجمع تبوك الطبي" -> "تجمع تبوك الصحي", "تبوك الطبي").
     */
    protected static function extractOrgPhrases(string $text): array
    {
        $orgs = [];
        $clean = trim($text);

        // Pattern 1: "[مجمع|تجمع|مركز|مستشفى|مديرية|صحة|قطاع] [Name] [الصحي/الطبية/العام]?"
        if (preg_match('/(?:مجمع|تجمع|مركز|مستشفى|مديرية|صحة|قطاع)\s+([\p{L}]+)(?:\s+(?:الصحي|الطبي|العام))?/u', $clean, $om)) {
            $matched = trim($om[0]);
            $cityOrName = trim($om[1]);
            $orgs[] = $matched;
            if (str_starts_with($matched, 'مجمع')) {
                $orgs[] = preg_replace('/^مجمع/u', 'تجمع', $matched);
            } elseif (str_starts_with($matched, 'تجمع')) {
                $orgs[] = preg_replace('/^تجمع/u', 'مجمع', $matched);
            }

            if (!self::isGenericToken($cityOrName)) {
                $orgs[] = "تجمع {$cityOrName} الصحي";
                $orgs[] = "مجمع {$cityOrName} الصحي";
                $orgs[] = "تجمع {$cityOrName} الطبي";
                $orgs[] = "مجمع {$cityOrName} الطبي";
                $orgs[] = "تجمع {$cityOrName}";
                $orgs[] = "مجمع {$cityOrName}";
                $orgs[] = "{$cityOrName} الصحي";
                $orgs[] = "{$cityOrName} الطبي";
                if (!self::isCommonRegion($cityOrName)) {
                    $orgs[] = $cityOrName;
                }
            }
        }

        // Pattern 2: "[City/Name] [الصحي|الصحية|الطبي|الطبية]" e.g. "تبوك الطبي"
        if (preg_match('/(?:^|\s)([\p{L}]+)\s+(?:الصحي|الصحية|الطبي|الطبية)/u', $clean, $sm)) {
            $cityOrName = trim($sm[1]);
            if (!self::isGenericToken($cityOrName)) {
                $orgs[] = "{$cityOrName} الصحي";
                $orgs[] = "{$cityOrName} الطبي";
                $orgs[] = "تجمع {$cityOrName} الصحي";
                $orgs[] = "مجمع {$cityOrName} الصحي";
                $orgs[] = "تجمع {$cityOrName} الطبي";
                $orgs[] = "مجمع {$cityOrName} الطبي";
                $orgs[] = "تجمع {$cityOrName}";
                $orgs[] = "مجمع {$cityOrName}";
                if (!self::isCommonRegion($cityOrName)) {
                    $orgs[] = $cityOrName;
                }
            }
        }

        return array_values(array_unique(array_filter($orgs)));
    }

    /**
     * Generate high-precision search queries to send to social platforms & web search.
     * Prevents social networks from doing loose token splitting.
     * Round-robin interleaves queries across all input keywords to guarantee fair quota allocation.
     */
    public static function generateTargetedSearchQueries(array $keywords): array
    {
        $cleanKeywords = array_values(array_filter(array_map('trim', $keywords)));
        if (empty($cleanKeywords)) {
            return [];
        }

        $queriesByKeyword = [];
        foreach ($cleanKeywords as $kw) {
            $kwQueries = [];
            $anchors = self::extractValidationAnchors($kw, $cleanKeywords);

            // 1. High-precision full phrases (quoted)
            foreach ($anchors['phrases'] as $phrase) {
                $p = trim($phrase);
                if (mb_strlen($p) >= 4 && !self::isGenericToken($p)) {
                    $kwQueries[] = "\"{$p}\"";
                }
            }

            // 2. High-precision person + context pairs (e.g. "متعب الحربي" "تبوك")
            foreach ($anchors['pairs'] as $pair) {
                if (count($pair) === 2) {
                    $kwQueries[] = "\"{$pair[0]}\" \"{$pair[1]}\"";
                }
            }

            // 3. Add clean original keyword if concise and not already present
            $wordCount = count(array_filter(explode(' ', $kw)));
            $quotedKw = "\"{$kw}\"";
            if ($wordCount <= 3 && !in_array($kw, $kwQueries) && !in_array($quotedKw, $kwQueries)) {
                $kwQueries[] = $kw;
            }

            $queriesByKeyword[] = array_values(array_unique(array_filter($kwQueries)));
        }

        // Interleave queries round-robin so every keyword gets fair search share
        $interleaved = [];
        $maxCount = 0;
        foreach ($queriesByKeyword as $list) {
            if (count($list) > $maxCount) {
                $maxCount = count($list);
            }
        }

        for ($i = 0; $i < $maxCount; $i++) {
            foreach ($queriesByKeyword as $list) {
                if (isset($list[$i])) {
                    $interleaved[] = $list[$i];
                }
            }
        }

        // Deduplicate while preserving round-robin order
        $finalQueries = [];
        foreach ($interleaved as $q) {
            if (!in_array($q, $finalQueries, true)) {
                $finalQueries[] = $q;
            }
        }

        // Filter out unquoted versions if quoted version exists
        $filtered = [];
        $quotedSet = [];
        foreach ($finalQueries as $q) {
            if (str_starts_with($q, '"') && str_ends_with($q, '"')) {
                $quotedSet[trim($q, '"')] = true;
            }
        }
        foreach ($finalQueries as $q) {
            if (isset($quotedSet[$q])) {
                continue; // Skip unquoted form if quoted form is present
            }
            $filtered[] = $q;
        }

        return $filtered;
    }

    /**
     * Detect if a post is clearly sports/football chatter when the search is non-sports.
     */
    public static function isSportsNoise(string $normContent, array $keywords): bool
    {
        $combinedKw = implode(' ', $keywords);
        if (preg_match('/(?:كورة|كرة قدم|نادي|دوري|لاعب|رياضة|الهلال|الشباب|النصر|الاتحاد)/u', $combinedKw)) {
            return false;
        }

        $sportsMarkers = [
            // Competitions & Tournaments
            'كاس الخليج', 'كأس الخليج', 'خليجي', 'خليجي27', 'خليجي 27', 'خليجي26', 'خليجي 26',
            'كاس اسيا', 'كأس آسيا', 'كاس العالم', 'كأس العالم', 'مونديال',
            'دوري روشن', 'دوري يلو', 'كاس الملك', 'كأس الملك', 'كاس السوبر', 'كأس السوبر',
            'دوري ابطال', 'دوري أبطال', 'دوري الابطال', 'دوري الأبطال',
            'بطولة', 'مباراة', 'مباريات', 'نهائي', 'نصف نهائي', 'ربع نهائي',
            // Clubs & National Teams
            'نادي الهلال', 'نادي النصر', 'نادي الأهلي', 'نادي الاهلي', 'نادي الاتحاد', 'نادي الشباب',
            'الهلال', 'النصر', 'الأهلي', 'الاهلي', 'الاتحاد', 'الشباب', 'الاتفاق', 'التعاون', 'القادسية',
            'الملكي', 'الزعيم', 'العالمي', 'العميد', 'الليث', 'نادي',
            'المنتخب', 'المنتخب السعودي', 'منتخبنا', 'الأخضر', 'الاخضر', 'الصقور الخضر',
            // Roles, Positions & Match Actions
            'لاعب كرة', 'لاعب كرة قدم', 'لاعب المنتخب', 'لاعب', 'اللاعب', 'لاعبي', 'لاعبين', 'اللاعبين',
            'كابتن', 'الكابتن', 'الظهير', 'جناح', 'حارس مرمى', 'مهاجم', 'مدافع',
            'مدرب', 'المدرب', 'تشكيلة', 'هدف', 'اهداف', 'أهداف', 'اسيست', 'بلنتي',
            'ضربة جزاء', 'ركلة جزاء', 'ركله جزاء', 'بطاقة صفراء', 'بطاقة حمراء',
            'طرد', 'تبديل', 'تسديدة', 'شوط', 'ديربي', 'كلاسيكو', 'مرمى', 'شباك', 'هاتريك',
            // Sports Media, Shows & Hashtags
            'صحيفة الرياضية', 'القناة الرياضية', 'قناة الرياضية', 'الرياضية', 'قناة الكاس', 'قناة الكأس',
            'برنامج أكشن', 'اكشن مع وليد', 'صدى الملاعب', 'في المرمى',
            'الرياضة على تيك توك', 'الرياضة_على_تيك_توك', 'كورة', 'رياضة', 'رياضي', 'كروي',
            // Video Games & Esports
            'fc24', 'fc25', 'fc26', 'fc27', 'fifa', 'فيفا', 'بلايستيشن', 'playstation',
            // Well-known sports figures co-occurring with player
            'محمد نور', 'سبيت خاطر', 'مانشيني', 'رينارد', 'جيسوس', 'خيسوس', 'سالم الدوسري', 'نيمار', 'رونالدو', 'ميتروفيتش'
        ];

        $paddedContent = ' ' . $normContent . ' ';

        $hasSports = false;
        foreach ($sportsMarkers as $sm) {
            $normMarker = self::normalize($sm);
            if (empty($normMarker) || mb_strlen($normMarker) < 2) {
                continue;
            }

            // Word-boundary check: " {marker} "
            if (mb_stripos($paddedContent, ' ' . $normMarker . ' ') !== false) {
                $hasSports = true;
                break;
            }

            // Compound multi-word check
            if (str_contains($normMarker, ' ') && mb_stripos($normContent, $normMarker) !== false) {
                $hasSports = true;
                break;
            }
        }

        if (!$hasSports) {
            return false;
        }

        // Only override if the post mentions explicit, official healthcare organizational entities
        $strictHealthContext = [
            'وزارة الصحة', 'تجمع تبوك الصحي', 'مجمع تبوك الطبي', 'مجمع تبوك الصحي',
            'تجمع تبوك الطبي', 'الشؤون الصحية', 'المديرية العامة للشؤون الصحية'
        ];
        foreach ($strictHealthContext as $hc) {
            if (mb_stripos($normContent, self::normalize($hc)) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Strictly evaluate whether the given content is relevant to the target keywords.
     */
    public static function isContentRelevant(string $content, array $keywords, ?string $campaignName = null): bool
    {
        $cleanKeywords = array_values(array_filter(array_map('trim', $keywords)));
        if (!empty($campaignName) && trim($campaignName) !== '') {
            $cleanKeywords[] = trim($campaignName);
        }

        if (empty($cleanKeywords)) {
            return true;
        }

        $normContent = self::normalize($content);
        if (empty($normContent) || mb_strlen($normContent) < 4) {
            return false;
        }

        // Strict rejection for sports chatter if monitoring executive/healthcare
        if (self::isSportsNoise($normContent, $cleanKeywords)) {
            return false;
        }

        foreach ($cleanKeywords as $kw) {
            $normKw = self::normalize($kw);
            if (empty($normKw)) continue;

            // Direct full keyword match in content
            if (mb_stripos($normContent, $normKw) !== false) {
                return true;
            }

            $anchors = self::extractValidationAnchors($kw, $cleanKeywords);

            // Check multi-word anchor phrases
            foreach ($anchors['phrases'] as $phrase) {
                $normPhrase = self::normalize($phrase);
                if (mb_strlen($normPhrase) >= 4 && mb_stripos($normContent, $normPhrase) !== false) {
                    return true;
                }
            }

            // Check required pairs
            foreach ($anchors['pairs'] as $pair) {
                $allPresent = true;
                foreach ($pair as $term) {
                    $normTerm = self::normalize($term);
                    if (mb_stripos($normContent, $normTerm) === false) {
                        $allPresent = false;
                        break;
                    }
                }
                if ($allPresent) {
                    return true;
                }
            }
        }

        return false;
    }
}
