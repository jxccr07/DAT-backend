<?php

namespace Database\Seeders\Support;

class UniversityCatalog
{
    private const TARGET_COURSE_COUNT = 45;

    private const SEMESTER_DISTRIBUTION = [
        1 => 3,
        2 => 3,
        3 => 3,
        4 => 3,
        5 => 6,
        6 => 6,
        7 => 4,
        8 => 5,
    ];

    private const COURSE_NAME_TEMPLATES = [
        'مهارات أكاديمية متقدمة في %s',
        'تطبيقات عملية في %s',
        'دراسات تحليلية في %s',
        'قضايا معاصرة في %s',
        'حل المشكلات في %s',
        'ورشة تخصصية في %s',
        'قراءات متقدمة في %s',
        'مشروع تطبيقي في %s',
    ];

    private const LAB_ENABLED_PLANS = [
        'computer_science',
        'information_technology',
        'artificial_intelligence',
        'arts',
    ];

    public static function plans(): array
    {
        return array_map(
            fn (array $plan): array => self::expandPlanCourses($plan, self::TARGET_COURSE_COUNT),
            self::basePlans()
        );
    }

    public static function allCourses(): array
    {
        $courses = [];

        foreach (self::plans() as $plan) {
            foreach ($plan['courses'] as $course) {
                $courses[] = $course;
            }
        }

        return $courses;
    }

    public static function allSpecializations(): array
    {
        $specializations = array_map(
            fn (array $course): string => $course['specializes_in'],
            self::allCourses()
        );

        $specializations = array_values(array_unique($specializations));
        sort($specializations);

        return $specializations;
    }

    private static function basePlans(): array
    {
        return [
            [
                'key' => 'computer_science',
                'name' => 'الخطة الأكاديمية لعلوم الحاسوب',
                'program' => 'بكالوريوس',
                'college' => 'كلية الحوسبة والمعلوماتية',
                'major' => 'علوم الحاسوب',
                'courses' => [
                    ['code' => 'CS101', 'name' => 'أساسيات البرمجة بالحاسوب', 'specializes_in' => 'programming', 'level' => 1, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 1],
                    ['code' => 'CS102', 'name' => 'الرياضيات المتقطعة للحوسبة', 'specializes_in' => 'mathematics', 'level' => 1, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 1],
                    ['code' => 'CS103', 'name' => 'المنطق الرقمي وتنظيم الحاسوب', 'specializes_in' => 'hardware', 'level' => 1, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 1],
                    ['code' => 'CS201', 'name' => 'البرمجة كائنية التوجه وتطبيقاتها', 'specializes_in' => 'programming', 'level' => 2, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 2],
                    ['code' => 'CS202', 'name' => 'هياكل البيانات وتحليلها', 'specializes_in' => 'algorithms', 'level' => 2, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 2],
                    ['code' => 'CS203', 'name' => 'قواعد البيانات ونمذجة المعلومات', 'specializes_in' => 'database_admin', 'level' => 2, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 2],
                    ['code' => 'CS301', 'name' => 'أنظمة التشغيل وإدارة الموارد', 'specializes_in' => 'operating_systems', 'level' => 3, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 3],
                    ['code' => 'CS302', 'name' => 'شبكات الحاسوب وبروتوكولات الاتصال', 'specializes_in' => 'manage_networking', 'level' => 3, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 3],
                    ['code' => 'CS303', 'name' => 'هندسة البرمجيات وإدارة المتطلبات', 'specializes_in' => 'software_engineering', 'level' => 3, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 3],
                    ['code' => 'CS401', 'name' => 'تصميم الخوارزميات المتقدمة', 'specializes_in' => 'algorithms', 'level' => 4, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 4],
                    ['code' => 'CS402', 'name' => 'أمن المعلومات وتطبيقات الحماية', 'specializes_in' => 'cyber_security', 'level' => 4, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 4],
                    ['code' => 'CS403', 'name' => 'مشروع التخرج في علوم الحاسوب', 'specializes_in' => 'graduation_project', 'level' => 4, 'hours' => 4, 'has_lab' => false, 'suggested_semester' => 4],
                ],
            ],
            [
                'key' => 'information_technology',
                'name' => 'الخطة الأكاديمية لتقنية المعلومات',
                'program' => 'بكالوريوس',
                'college' => 'كلية الحوسبة والمعلوماتية',
                'major' => 'تقنية المعلومات',
                'courses' => [
                    ['code' => 'IT101', 'name' => 'مدخل إلى تقنية المعلومات الحديثة', 'specializes_in' => 'information_technology', 'level' => 1, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 1],
                    ['code' => 'IT102', 'name' => 'مهارات الأنظمة المكتبية والشبكية', 'specializes_in' => 'information_technology', 'level' => 1, 'hours' => 2, 'has_lab' => true, 'suggested_semester' => 1],
                    ['code' => 'IT103', 'name' => 'أساسيات البنية التحتية الرقمية', 'specializes_in' => 'it_infrastructure', 'level' => 1, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 1],
                    ['code' => 'IT201', 'name' => 'تحليل النظم وتصميم الحلول', 'specializes_in' => 'systems_analysis', 'level' => 2, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 2],
                    ['code' => 'IT202', 'name' => 'إدارة قواعد البيانات التطبيقية', 'specializes_in' => 'database_admin', 'level' => 2, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 2],
                    ['code' => 'IT203', 'name' => 'تطوير تطبيقات الويب المؤسسية', 'specializes_in' => 'web_development', 'level' => 2, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 2],
                    ['code' => 'IT301', 'name' => 'إدارة الشبكات والخدمات التقنية', 'specializes_in' => 'manage_networking', 'level' => 3, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 3],
                    ['code' => 'IT302', 'name' => 'الحوسبة السحابية وإدارة الخوادم', 'specializes_in' => 'cloud_computing', 'level' => 3, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 3],
                    ['code' => 'IT303', 'name' => 'أساسيات أمن البنية المعلوماتية', 'specializes_in' => 'cyber_security', 'level' => 3, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 3],
                    ['code' => 'IT401', 'name' => 'إدارة مشاريع تقنية المعلومات', 'specializes_in' => 'project_management', 'level' => 4, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 4],
                    ['code' => 'IT402', 'name' => 'إدارة مراكز البيانات والدعم التقني', 'specializes_in' => 'it_operations', 'level' => 4, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 4],
                    ['code' => 'IT403', 'name' => 'التدريب الميداني في تقنية المعلومات', 'specializes_in' => 'field_training', 'level' => 4, 'hours' => 4, 'has_lab' => false, 'suggested_semester' => 4],
                ],
            ],
            [
                'key' => 'law',
                'name' => 'الخطة الأكاديمية للقانون',
                'program' => 'بكالوريوس',
                'college' => 'كلية القانون',
                'major' => 'القانون',
                'courses' => [
                    ['code' => 'LAW101', 'name' => 'مدخل إلى العلوم القانونية', 'specializes_in' => 'law', 'level' => 1, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 1],
                    ['code' => 'LAW102', 'name' => 'النظرية العامة للحق', 'specializes_in' => 'law', 'level' => 1, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 1],
                    ['code' => 'LAW103', 'name' => 'القانون الدستوري والنظم السياسية', 'specializes_in' => 'constitutional_law', 'level' => 1, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 1],
                    ['code' => 'LAW201', 'name' => 'القانون المدني والالتزامات', 'specializes_in' => 'civil_law', 'level' => 2, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 2],
                    ['code' => 'LAW202', 'name' => 'القانون الجنائي العام', 'specializes_in' => 'criminal_law', 'level' => 2, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 2],
                    ['code' => 'LAW203', 'name' => 'القانون الإداري والوظيفة العامة', 'specializes_in' => 'administrative_law', 'level' => 2, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 2],
                    ['code' => 'LAW301', 'name' => 'القانون التجاري والأوراق التجارية', 'specializes_in' => 'commercial_law', 'level' => 3, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 3],
                    ['code' => 'LAW302', 'name' => 'الإجراءات الجزائية وطرق التقاضي', 'specializes_in' => 'procedural_law', 'level' => 3, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 3],
                    ['code' => 'LAW303', 'name' => 'القانون الدولي العام والعلاقات الدولية', 'specializes_in' => 'international_law', 'level' => 3, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 3],
                    ['code' => 'LAW401', 'name' => 'صياغة العقود والمذكرات القانونية', 'specializes_in' => 'legal_drafting', 'level' => 4, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 4],
                    ['code' => 'LAW402', 'name' => 'قانون العمل والتأمينات الاجتماعية', 'specializes_in' => 'labor_law', 'level' => 4, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 4],
                    ['code' => 'LAW403', 'name' => 'التدريب العملي في العيادة القانونية', 'specializes_in' => 'legal_clinic', 'level' => 4, 'hours' => 4, 'has_lab' => false, 'suggested_semester' => 4],
                ],
            ],
            [
                'key' => 'sharia',
                'name' => 'الخطة الأكاديمية للشريعة',
                'program' => 'بكالوريوس',
                'college' => 'كلية الشريعة وأصول الدين',
                'major' => 'الشريعة',
                'courses' => [
                    ['code' => 'SHR101', 'name' => 'مدخل إلى الفقه وأقسامه', 'specializes_in' => 'sharia', 'level' => 1, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 1],
                    ['code' => 'SHR102', 'name' => 'أصول الفقه ومناهج الاستدلال', 'specializes_in' => 'usul_al_fiqh', 'level' => 1, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 1],
                    ['code' => 'SHR103', 'name' => 'علوم القرآن والتفسير الميسر', 'specializes_in' => 'quranic_studies', 'level' => 1, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 1],
                    ['code' => 'SHR201', 'name' => 'فقه العبادات وأدلته', 'specializes_in' => 'fiqh_worship', 'level' => 2, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 2],
                    ['code' => 'SHR202', 'name' => 'مصطلح الحديث وعلومه', 'specializes_in' => 'hadith_studies', 'level' => 2, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 2],
                    ['code' => 'SHR203', 'name' => 'فقه المعاملات المالية المعاصرة', 'specializes_in' => 'fiqh_transactions', 'level' => 2, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 2],
                    ['code' => 'SHR301', 'name' => 'فقه الأسرة والأحوال الشخصية', 'specializes_in' => 'personal_status', 'level' => 3, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 3],
                    ['code' => 'SHR302', 'name' => 'القواعد الفقهية وتطبيقاتها', 'specializes_in' => 'fiqh_rules', 'level' => 3, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 3],
                    ['code' => 'SHR303', 'name' => 'مقاصد الشريعة والسياسة الشرعية', 'specializes_in' => 'maqasid_sharia', 'level' => 3, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 3],
                    ['code' => 'SHR401', 'name' => 'القضاء الشرعي وإجراءات التقاضي', 'specializes_in' => 'sharia_judiciary', 'level' => 4, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 4],
                    ['code' => 'SHR402', 'name' => 'النوازل الفقهية المعاصرة', 'specializes_in' => 'contemporary_fiqh', 'level' => 4, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 4],
                    ['code' => 'SHR403', 'name' => 'مشروع بحث في الدراسات الشرعية', 'specializes_in' => 'sharia_research', 'level' => 4, 'hours' => 4, 'has_lab' => false, 'suggested_semester' => 4],
                ],
            ],
            [
                'key' => 'artificial_intelligence',
                'name' => 'الخطة الأكاديمية للذكاء الاصطناعي',
                'program' => 'بكالوريوس',
                'college' => 'كلية الحوسبة والمعلوماتية',
                'major' => 'الذكاء الاصطناعي',
                'courses' => [
                    ['code' => 'AI101', 'name' => 'مقدمة في الذكاء الاصطناعي الحديث', 'specializes_in' => 'artificial_intelligence', 'level' => 1, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 1],
                    ['code' => 'AI102', 'name' => 'برمجة بايثون لتحليل البيانات', 'specializes_in' => 'data_analysis', 'level' => 1, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 1],
                    ['code' => 'AI103', 'name' => 'الرياضيات التطبيقية للتعلم الآلي', 'specializes_in' => 'machine_learning_math', 'level' => 1, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 1],
                    ['code' => 'AI201', 'name' => 'التعلم الآلي وبناء النماذج', 'specializes_in' => 'machine_learning', 'level' => 2, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 2],
                    ['code' => 'AI202', 'name' => 'هندسة البيانات للأنظمة الذكية', 'specializes_in' => 'data_engineering', 'level' => 2, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 2],
                    ['code' => 'AI203', 'name' => 'تمثيل المعرفة والاستدلال', 'specializes_in' => 'knowledge_representation', 'level' => 2, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 2],
                    ['code' => 'AI301', 'name' => 'الشبكات العصبية والتعلم العميق', 'specializes_in' => 'deep_learning', 'level' => 3, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 3],
                    ['code' => 'AI302', 'name' => 'الرؤية الحاسوبية ومعالجة الصور', 'specializes_in' => 'computer_vision', 'level' => 3, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 3],
                    ['code' => 'AI303', 'name' => 'معالجة اللغة الطبيعية وتحليل النصوص', 'specializes_in' => 'natural_language_processing', 'level' => 3, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 3],
                    ['code' => 'AI401', 'name' => 'نشر النماذج الذكية وتشغيلها', 'specializes_in' => 'model_deployment', 'level' => 4, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 4],
                    ['code' => 'AI402', 'name' => 'أخلاقيات الذكاء الاصطناعي وحوكمته', 'specializes_in' => 'ai_governance', 'level' => 4, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 4],
                    ['code' => 'AI403', 'name' => 'مشروع التخرج في الذكاء الاصطناعي', 'specializes_in' => 'graduation_project', 'level' => 4, 'hours' => 4, 'has_lab' => false, 'suggested_semester' => 4],
                ],
            ],
            [
                'key' => 'business_admin',
                'name' => 'الخطة الأكاديمية لإدارة الأعمال',
                'program' => 'بكالوريوس',
                'college' => 'كلية إدارة الأعمال',
                'major' => 'إدارة الأعمال',
                'courses' => [
                    ['code' => 'BUS101', 'name' => 'مدخل إلى إدارة الأعمال المعاصرة', 'specializes_in' => 'business_admin', 'level' => 1, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 1],
                    ['code' => 'BUS102', 'name' => 'مبادئ المحاسبة المالية', 'specializes_in' => 'accounting', 'level' => 1, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 1],
                    ['code' => 'BUS103', 'name' => 'الاقتصاد الجزئي لطلاب الإدارة', 'specializes_in' => 'economics', 'level' => 1, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 1],
                    ['code' => 'BUS201', 'name' => 'إدارة التسويق وسلوك المستهلك', 'specializes_in' => 'marketing', 'level' => 2, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 2],
                    ['code' => 'BUS202', 'name' => 'إدارة الموارد البشرية والتطوير', 'specializes_in' => 'human_resources', 'level' => 2, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 2],
                    ['code' => 'BUS203', 'name' => 'الإحصاء التطبيقي للأعمال', 'specializes_in' => 'business_statistics', 'level' => 2, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 2],
                    ['code' => 'BUS301', 'name' => 'إدارة العمليات وسلاسل الإمداد', 'specializes_in' => 'operations_management', 'level' => 3, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 3],
                    ['code' => 'BUS302', 'name' => 'الإدارة المالية واتخاذ القرار', 'specializes_in' => 'finance', 'level' => 3, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 3],
                    ['code' => 'BUS303', 'name' => 'ريادة الأعمال وتطوير المشاريع', 'specializes_in' => 'entrepreneurship', 'level' => 3, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 3],
                    ['code' => 'BUS401', 'name' => 'الإدارة الاستراتيجية وتحليل البيئة', 'specializes_in' => 'strategic_management', 'level' => 4, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 4],
                    ['code' => 'BUS402', 'name' => 'أنظمة المعلومات الإدارية', 'specializes_in' => 'management_information_systems', 'level' => 4, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 4],
                    ['code' => 'BUS403', 'name' => 'مشروع التخرج في إدارة الأعمال', 'specializes_in' => 'graduation_project', 'level' => 4, 'hours' => 4, 'has_lab' => false, 'suggested_semester' => 4],
                ],
            ],
            [
                'key' => 'arts',
                'name' => 'الخطة الأكاديمية للفنون',
                'program' => 'بكالوريوس',
                'college' => 'كلية الفنون والتصميم',
                'major' => 'الفنون',
                'courses' => [
                    ['code' => 'ART101', 'name' => 'مدخل إلى تاريخ الفنون البصرية', 'specializes_in' => 'arts', 'level' => 1, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 1],
                    ['code' => 'ART102', 'name' => 'أساسيات الرسم والملاحظة البصرية', 'specializes_in' => 'visual_arts', 'level' => 1, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 1],
                    ['code' => 'ART103', 'name' => 'نظرية اللون والتكوين الفني', 'specializes_in' => 'design_theory', 'level' => 1, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 1],
                    ['code' => 'ART201', 'name' => 'الفنون الرقمية وأدوات التصميم', 'specializes_in' => 'digital_arts', 'level' => 2, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 2],
                    ['code' => 'ART202', 'name' => 'التصوير الفوتوغرافي والإخراج البصري', 'specializes_in' => 'photography', 'level' => 2, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 2],
                    ['code' => 'ART203', 'name' => 'الطباعة الفنية والخط البصري', 'specializes_in' => 'typography', 'level' => 2, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 2],
                    ['code' => 'ART301', 'name' => 'الرسوم التوضيحية والسرد البصري', 'specializes_in' => 'illustration', 'level' => 3, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 3],
                    ['code' => 'ART302', 'name' => 'تصميم الهوية البصرية والعلامة', 'specializes_in' => 'visual_identity', 'level' => 3, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 3],
                    ['code' => 'ART303', 'name' => 'النقد الفني وقراءة الأعمال', 'specializes_in' => 'art_criticism', 'level' => 3, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 3],
                    ['code' => 'ART401', 'name' => 'تصميم المعارض وتجربة الزائر', 'specializes_in' => 'exhibition_design', 'level' => 4, 'hours' => 3, 'has_lab' => true, 'suggested_semester' => 4],
                    ['code' => 'ART402', 'name' => 'اتجاهات الفن المعاصر وممارساته', 'specializes_in' => 'contemporary_arts', 'level' => 4, 'hours' => 3, 'has_lab' => false, 'suggested_semester' => 4],
                    ['code' => 'ART403', 'name' => 'مشروع التخرج في الفنون والتصميم', 'specializes_in' => 'graduation_project', 'level' => 4, 'hours' => 4, 'has_lab' => true, 'suggested_semester' => 4],
                ],
            ],
        ];
    }

    private static function expandPlanCourses(array $plan, int $targetCount): array
    {
        if (count($plan['courses']) >= $targetCount) {
            return $plan;
        }

        $existingCounts = self::existingCourseCountsBySemester($plan['courses']);

        $extraCourses = [];
        $focuses = self::majorFocuses($plan['key'], $plan['major']);
        $prefix = self::coursePrefix($plan['courses'][0]['code']);
        $focusIndex = 0;

        foreach (self::SEMESTER_DISTRIBUTION as $semester => $count) {
            $startSlot = ($existingCounts[$semester] ?? 0) + 1;

            for ($slot = 0; $slot < $count; $slot++) {
                $extraCourses[] = self::generatedCourse(
                    $plan['key'],
                    $prefix,
                    $focuses,
                    $semester,
                    $startSlot + $slot,
                    $slot,
                    $focusIndex
                );
                $focusIndex++;
            }
        }

        $plan['courses'] = array_slice(array_merge($plan['courses'], $extraCourses), 0, $targetCount);

        return $plan;
    }

    private static function existingCourseCountsBySemester(array $courses): array
    {
        $counts = [];

        foreach ($courses as $course) {
            $semester = $course['suggested_semester'];
            $counts[$semester] = ($counts[$semester] ?? 0) + 1;
        }

        return $counts;
    }

    private static function generatedCourse(
        string $planKey,
        string $prefix,
        array $focuses,
        int $semester,
        int $courseSlot,
        int $slot,
        int $focusIndex
    ): array {
        $focusPosition = $focusIndex % count($focuses);
        $focus = $focuses[$focusPosition];

        return [
            'code' => $prefix.(($semester * 100) + $courseSlot),
            'name' => self::generatedCourseName($focus['topic'], $semester, $slot),
            'specializes_in' => $focus['specialization'],
            'level' => self::levelForSemester($semester),
            'hours' => self::hoursForSemester($semester, $slot),
            'has_lab' => self::hasLabForPlan($planKey, $semester, $slot),
            'suggested_semester' => $semester,
        ];
    }

    private static function coursePrefix(string $code): string
    {
        preg_match('/^[A-Z]+/', $code, $matches);

        return $matches[0] ?? 'CRS';
    }

    private static function generatedCourseName(string $focus, int $semester, int $slot): string
    {
        $template = self::COURSE_NAME_TEMPLATES[($semester + $slot) % count(self::COURSE_NAME_TEMPLATES)];

        return sprintf($template, $focus);
    }

    private static function levelForSemester(int $semester): int
    {
        return (int) ceil($semester / 2);
    }

    private static function hoursForSemester(int $semester, int $slot): int
    {
        if ($semester === 8 && $slot >= 3) {
            return 4;
        }

        return ($slot % 4 === 0) ? 2 : 3;
    }

    private static function hasLabForPlan(string $planKey, int $semester, int $slot): bool
    {
        return in_array($planKey, self::LAB_ENABLED_PLANS, true) && (($semester + $slot) % 3 === 0);
    }

    private static function majorFocuses(string $planKey, string $major): array
    {
        return match ($planKey) {
            'computer_science' => [
                ['topic' => 'البرمجة', 'specialization' => 'programming'],
                ['topic' => 'الخوارزميات', 'specialization' => 'algorithms'],
                ['topic' => 'هندسة البرمجيات', 'specialization' => 'software_engineering'],
                ['topic' => 'الأنظمة الذكية', 'specialization' => 'artificial_intelligence'],
                ['topic' => 'الحوسبة الموزعة', 'specialization' => 'distributed_computing'],
                ['topic' => 'تحليل البيانات', 'specialization' => 'data_analysis'],
                ['topic' => 'الأمن السيبراني', 'specialization' => 'cyber_security'],
                ['topic' => 'الأنظمة المدمجة', 'specialization' => 'embedded_systems'],
                ['topic' => 'قواعد البيانات', 'specialization' => 'database_admin'],
                ['topic' => 'أنظمة التشغيل', 'specialization' => 'operating_systems'],
            ],
            'information_technology' => [
                ['topic' => 'البنية التحتية الرقمية', 'specialization' => 'it_infrastructure'],
                ['topic' => 'إدارة الأنظمة', 'specialization' => 'systems_administration'],
                ['topic' => 'الخدمات السحابية', 'specialization' => 'cloud_computing'],
                ['topic' => 'الدعم التقني', 'specialization' => 'technical_support'],
                ['topic' => 'حوكمة التقنية', 'specialization' => 'it_governance'],
                ['topic' => 'أمن المعلومات', 'specialization' => 'cyber_security'],
                ['topic' => 'تشغيل الشبكات', 'specialization' => 'manage_networking'],
                ['topic' => 'تحليل الأعمال التقنية', 'specialization' => 'systems_analysis'],
                ['topic' => 'تكامل الأنظمة', 'specialization' => 'systems_integration'],
                ['topic' => 'تطوير الويب', 'specialization' => 'web_development'],
            ],
            'law' => [
                ['topic' => 'القانون المدني', 'specialization' => 'civil_law'],
                ['topic' => 'القانون التجاري', 'specialization' => 'commercial_law'],
                ['topic' => 'القانون الجنائي', 'specialization' => 'criminal_law'],
                ['topic' => 'القانون الدولي', 'specialization' => 'international_law'],
                ['topic' => 'الصياغة القانونية', 'specialization' => 'legal_drafting'],
                ['topic' => 'إجراءات التقاضي', 'specialization' => 'procedural_law'],
                ['topic' => 'القانون الإداري', 'specialization' => 'administrative_law'],
                ['topic' => 'القانون الدستوري', 'specialization' => 'constitutional_law'],
                ['topic' => 'قانون العمل', 'specialization' => 'labor_law'],
                ['topic' => 'الممارسة القانونية', 'specialization' => 'legal_clinic'],
            ],
            'sharia' => [
                ['topic' => 'أصول الفقه', 'specialization' => 'usul_al_fiqh'],
                ['topic' => 'فقه المعاملات', 'specialization' => 'fiqh_transactions'],
                ['topic' => 'فقه الأسرة', 'specialization' => 'personal_status'],
                ['topic' => 'مقاصد الشريعة', 'specialization' => 'maqasid_sharia'],
                ['topic' => 'السياسة الشرعية', 'specialization' => 'sharia'],
                ['topic' => 'النوازل الفقهية', 'specialization' => 'contemporary_fiqh'],
                ['topic' => 'علوم الحديث', 'specialization' => 'hadith_studies'],
                ['topic' => 'علوم القرآن', 'specialization' => 'quranic_studies'],
                ['topic' => 'القضاء الشرعي', 'specialization' => 'sharia_judiciary'],
                ['topic' => 'البحث الشرعي', 'specialization' => 'sharia_research'],
            ],
            'artificial_intelligence' => [
                ['topic' => 'التعلم الآلي', 'specialization' => 'machine_learning'],
                ['topic' => 'الشبكات العصبية', 'specialization' => 'deep_learning'],
                ['topic' => 'معالجة اللغة الطبيعية', 'specialization' => 'natural_language_processing'],
                ['topic' => 'الرؤية الحاسوبية', 'specialization' => 'computer_vision'],
                ['topic' => 'هندسة البيانات', 'specialization' => 'data_engineering'],
                ['topic' => 'تحليل البيانات', 'specialization' => 'data_analysis'],
                ['topic' => 'حوكمة الذكاء الاصطناعي', 'specialization' => 'ai_governance'],
                ['topic' => 'نشر النماذج', 'specialization' => 'model_deployment'],
                ['topic' => 'تمثيل المعرفة', 'specialization' => 'knowledge_representation'],
                ['topic' => 'الذكاء الاصطناعي التطبيقي', 'specialization' => 'artificial_intelligence'],
            ],
            'business_admin' => [
                ['topic' => 'الإدارة الاستراتيجية', 'specialization' => 'strategic_management'],
                ['topic' => 'المحاسبة', 'specialization' => 'accounting'],
                ['topic' => 'التمويل', 'specialization' => 'finance'],
                ['topic' => 'التسويق', 'specialization' => 'marketing'],
                ['topic' => 'ريادة الأعمال', 'specialization' => 'entrepreneurship'],
                ['topic' => 'إدارة الموارد البشرية', 'specialization' => 'human_resources'],
                ['topic' => 'تحليل الأعمال', 'specialization' => 'business_statistics'],
                ['topic' => 'سلاسل الإمداد', 'specialization' => 'operations_management'],
                ['topic' => 'الاقتصاد الإداري', 'specialization' => 'economics'],
                ['topic' => 'أنظمة المعلومات الإدارية', 'specialization' => 'management_information_systems'],
            ],
            'arts' => [
                ['topic' => 'الفنون البصرية', 'specialization' => 'visual_arts'],
                ['topic' => 'الرسم', 'specialization' => 'arts'],
                ['topic' => 'التصميم الرقمي', 'specialization' => 'digital_arts'],
                ['topic' => 'التصوير', 'specialization' => 'photography'],
                ['topic' => 'السرد البصري', 'specialization' => 'illustration'],
                ['topic' => 'النقد الفني', 'specialization' => 'art_criticism'],
                ['topic' => 'إدارة المعارض', 'specialization' => 'exhibition_design'],
                ['topic' => 'تصميم الهوية', 'specialization' => 'visual_identity'],
                ['topic' => 'التكوين اللوني', 'specialization' => 'design_theory'],
                ['topic' => 'الفنون المعاصرة', 'specialization' => 'contemporary_arts'],
            ],
            default => [
                ['topic' => $major, 'specialization' => str_replace(' ', '_', mb_strtolower($major))],
            ],
        };
    }
}
