<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines (Arabic)
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'يجب قبول :attribute.',
    'accepted_if' => 'يجب قبول :attribute عندما يكون :other هو :value.',
    'active_url' => ':attribute ليس عنوان URL صالحاً.',
    'after' => 'يجب أن يكون :attribute تاريخاً بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute تاريخاً لاحقاً أو مطابقاً لـ :date.',
    'alpha' => 'يجب أن يحتوي :attribute على أحرف فقط.',
    'alpha_dash' => 'يجب أن يحتوي :attribute على أحرف وأرقام وشرطات وشرطات سفلية فقط.',
    'alpha_num' => 'يجب أن يحتوي :attribute على أحرف وأرقام فقط.',
    'array' => 'يجب أن يكون :attribute مصفوفة.',
    'any_of' => 'قيمة :attribute غير صالحة.',
    'array_keys' => 'يحتوي حقل :attribute على مفاتيح غير مسموح بها.',
    'base64' => 'يجب أن يكون حقل :attribute نصاً صالحاً بترميز Base64.',
    'ascii' => 'يجب أن يحتوي :attribute على أحرف ورموز أبجدية رقمية أحادية البايت فقط.',
    'before' => 'يجب أن يكون :attribute تاريخاً قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute تاريخاً سابقاً أو مطابقاً لـ :date.',
    'between' => [
        'array' => 'يجب أن يحتوي :attribute على ما بين :min و :max عنصراً.',
        'file' => 'يجب أن يتراوح حجم ملف :attribute بين :min و :max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و :max.',
        'string' => 'يجب أن يتراوح عدد حروف :attribute بين :min و :max حرفاً.',
    ],
    'boolean' => 'يجب أن يكون حقل :attribute صحيحاً أو خاطئاً.',
    'can' => 'يحتوي حقل :attribute على قيمة غير مصرح بها.',
    'confirmed' => 'تأكيد :attribute غير متطابق.',
    'contains' => 'حقل :attribute يفتقد إلى قيمة مطلوبة.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => ':attribute ليس تاريخاً صالحاً.',
    'date_equals' => 'يجب أن يكون :attribute تاريخاً مطابقاً لـ :date.',
    'date_format' => ':attribute لا يتطابق مع الصيغة :format.',
    'decimal' => 'يجب أن يحتوي :attribute على :decimal منازل عشرية.',
    'declined' => 'يجب رفض :attribute.',
    'declined_if' => 'يجب رفض :attribute عندما يكون :other هو :value.',
    'different' => 'يجب أن يكون :attribute و :other مختلفين.',
    'digits' => 'يجب أن يتكون :attribute من :digits أرقام.',
    'digits_between' => 'يجب أن يكون :attribute بين :min و :max أرقام.',
    'dimensions' => ':attribute يحتوي على أبعاد صورة غير صالحة.',
    'distinct' => 'حقل :attribute يحتوي على قيمة مكررة.',
    'doesnt_contain' => 'يجب ألا يحتوي حقل :attribute على أي من القيم التالية: :values.',
    'doesnt_end_with' => 'يجب ألا ينتهي :attribute بأحد القيم التالية: :values.',
    'doesnt_start_with' => 'يجب ألا يبدأ :attribute بأحد القيم التالية: :values.',
    'email' => 'يجب أن يكون :attribute عنوان بريد إلكتروني صالحاً.',
    'encoding' => 'يجب أن يستخدم حقل :attribute ترميز :encoding.',
    'ends_with' => 'يجب أن ينتهي :attribute بأحد القيم التالية: :values.',
    'enum' => 'القيمة المحددة لـ :attribute غير صالحة.',
    'exists' => 'القيمة المحددة لـ :attribute غير صالحة.',
    'extensions' => 'يجب أن يحتوي حقل :attribute على أحد الامتدادات التالية: :values.',
    'file' => 'يجب أن يكون :attribute ملفاً.',
    'filled' => 'حقل :attribute إجباري.',
    'gt' => [
        'array' => 'يجب أن يحتوي :attribute على أكثر من :value عنصراً.',
        'file' => 'يجب أن يكون حجم ملف :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من :value.',
        'string' => 'يجب أن يكون طول نص :attribute أكبر من :value حرفاً.',
    ],
    'gte' => [
        'array' => 'يجب أن يحتوي :attribute على :value عنصراً على الأقل.',
        'file' => 'يجب أن يكون حجم ملف :attribute أكبر من أو يساوي :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من أو مساوية لـ :value.',
        'string' => 'يجب أن يكون طول نص :attribute مساوياً أو أكبر من :value حرفاً.',
    ],
    'hex_color' => 'يجب أن يكون حقل :attribute لوناً ست عشرياً صالحاً.',
    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => 'القيمة المحددة لـ :attribute غير صالحة.',
    'in_array' => 'حقل :attribute غير موجود في :other.',
    'in_array_keys' => 'يجب أن يحتوي حقل :attribute على أحد المفاتيح التالية على الأقل: :values.',
    'integer' => 'يجب أن يكون :attribute رقماً صحيحاً.',
    'ip' => 'يجب أن يكون :attribute عنوان IP صالحاً.',
    'ipv4' => 'يجب أن يكون :attribute عنوان IPv4 صالحاً.',
    'ipv6' => 'يجب أن يكون :attribute عنوان IPv6 صالحاً.',
    'json' => 'يجب أن يكون :attribute نصاً من نوع JSON صالحاً.',
    'list' => 'يجب أن يكون حقل :attribute قائمة.',
    'lowercase' => 'يجب أن يكون :attribute بحروف صغيرة.',
    'lt' => [
        'array' => 'يجب أن يحتوي :attribute على أقل من :value عنصراً.',
        'file' => 'يجب أن يكون حجم ملف :attribute أقل من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أقل من :value.',
        'string' => 'يجب أن يكون طول نص :attribute أقل من :value حرفاً.',
    ],
    'lte' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :value عنصراً.',
        'file' => 'يجب أن يكون حجم ملف :attribute أقل من أو يساوي :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أقل من أو مساوية لـ :value.',
        'string' => 'يجب أن يكون طول نص :attribute مساوياً أو أقل من :value حرفاً.',
    ],
    'mac_address' => 'يجب أن يكون :attribute عنوان MAC صالحاً.',
    'max' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عنصراً.',
        'file' => 'يجب ألا يتجاوز حجم ملف :attribute :max كيلوبايت.',
        'numeric' => 'يجب ألا تكون قيمة :attribute أكبر من :max.',
        'string' => 'يجب ألا يتجاوز طول نص :attribute :max حرفاً.',
    ],
    'max_digits' => 'يجب ألا يحتوي :attribute على أكثر من :max أرقام.',
    'mimes' => 'يجب أن يكون :attribute ملفاً من نوع: :values.',
    'mimetypes' => 'يجب أن يكون :attribute ملفاً من نوع: :values.',
    'min' => [
        'array' => 'يجب أن يحتوي :attribute على ما لا يقل عن :min من العناصر.',
        'file' => 'يجب أن يكون حجم ملف :attribute ما لا يقل عن :min كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute ما لا يقل عن :min.',
        'string' => 'يجب أن يكون طول نص :attribute ما لا يقل عن :min حروف.',
    ],
    'min_digits' => 'يجب أن يحتوي :attribute على :min أرقام على الأقل.',
    'missing' => 'يجب أن يكون حقل :attribute مفقوداً.',
    'missing_if' => 'يجب أن يكون حقل :attribute مفقوداً عندما يكون :other هو :value.',
    'missing_unless' => 'يجب أن يكون حقل :attribute مفقوداً إلا إذا كان :other هو :value.',
    'missing_with' => 'يجب أن يكون حقل :attribute مفقوداً عند وجود :values.',
    'missing_with_all' => 'يجب أن يكون حقل :attribute مفقوداً عند وجود :values.',
    'multiple_of' => 'يجب أن يكون :attribute مضاعفاً للقيمة :value.',
    'not_in' => 'القيمة المحددة لـ :attribute غير صالحة.',
    'not_regex' => 'صيغة :attribute غير صالحة.',
    'numeric' => 'يجب أن يكون :attribute رقماً.',
    'password' => [
        'letters' => 'يجب أن تحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن تحتوي :attribute على حرف كبير وآخر صغير على الأقل.',
        'numbers' => 'يجب أن تحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن تحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => ':attribute المعطى ظهر في تسريب بيانات. يرجى اختيار :attribute مختلف.',
    ],
    'present' => 'يجب تقديم حقل :attribute.',
    'present_if' => 'يجب تقديم حقل :attribute عندما يكون :other هو :value.',
    'present_unless' => 'يجب تقديم حقل :attribute إلا إذا كان :other هو :value.',
    'present_with' => 'يجب تقديم حقل :attribute عند وجود :values.',
    'present_with_all' => 'يجب تقديم حقل :attribute عند وجود :values.',
    'prohibited' => 'حقل :attribute محظور.',
    'prohibited_if' => 'حقل :attribute محظور عندما يكون :other هو :value.',
    'prohibited_if_accepted' => 'حقل :attribute محظور عند قبول :other.',
    'prohibited_if_declined' => 'حقل :attribute محظور عند رفض :other.',
    'prohibited_unless' => 'حقل :attribute محظور ما لم يكن :other في :values.',
    'prohibits' => 'حقل :attribute يحظر وجود :other.',
    'regex' => 'صيغة :attribute غير صالحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_array_keys' => 'يجب أن يحتوي حقل :attribute على مدخلات لـ: :values.',
    'required_if' => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_if_accepted' => 'حقل :attribute مطلوب عندما يتم قبول :other.',
    'required_if_declined' => 'حقل :attribute مطلوب عندما يتم رفض :other.',
    'required_unless' => 'حقل :attribute مطلوب ما لم يكن :other في :values.',
    'required_with' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_with_all' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_without' => 'حقل :attribute مطلوب عندما لا يكون :values موجوداً.',
    'required_without_all' => 'حقل :attribute مطلوب عندما لا يكون أي من :values موجوداً.',
    'same' => 'يجب أن يتطابق :attribute مع :other.',
    'size' => [
        'array' => 'يجب أن يحتوي :attribute على :size عنصراً.',
        'file' => 'يجب أن يكون حجم ملف :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute مساوية لـ :size.',
        'string' => 'يجب أن يحتوي :attribute على :size حرفاً.',
    ],
    'starts_with' => 'يجب أن يبدأ :attribute بأحد القيم التالية: :values.',
    'string' => 'يجب أن يكون :attribute نصاً.',
    'timezone' => 'يجب أن يكون :attribute نطاقاً زمنياً صالحاً.',
    'unique' => ':attribute مُستخدم من قبل.',
    'uploaded' => 'فشل تحميل :attribute.',
    'uppercase' => 'يجب أن يكون :attribute بحروف كبيرة.',
    'url' => 'يجب أن يكون :attribute عنوان URL صالحاً.',
    'ulid' => 'يجب أن يكون :attribute معرف ULID صالحاً.',
    'uuid' => 'يجب أن يكون :attribute معرف UUID صالحاً.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [
        // App / Auth
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'current_password' => 'كلمة المرور الحالية',
        'remember' => 'تذكرني',
        'role' => 'الدور',
        'locale' => 'اللغة',
        'timezone' => 'المنطقة الزمنية',
        'public_id' => 'المعرف العام',

        // Company Identity & Settings
        'name_ar' => 'الاسم بالعربية',
        'name_en' => 'الاسم بالإنجليزية',
        'legal_name_ar' => 'الاسم القانوني بالعربية',
        'legal_name_en' => 'الاسم القانوني بالإنجليزية',
        'registration_number' => 'رقم السجل التجاري',
        'tax_number' => 'الرقم الضريبي',
        'phone' => 'رقم الهاتف',
        'whatsapp' => 'رقم الواتساب',
        'base_currency_code' => 'العملة الأساسية',
        'currency_code' => 'رمز العملة',
        'exchange_rate' => 'سعر الصرف',
        'status' => 'الحالة',
        'active' => 'نشط',

        // Catalog & Inventory
        'sku' => 'رمز الصنف (SKU)',
        'barcode' => 'الباركود',
        'category_id' => 'التصنيف',
        'brand_id' => 'العلامة التجارية',
        'base_unit_id' => 'الوحدة الأساسية',
        'product_type' => 'نوع الصنف',
        'track_stock' => 'تتبع المخزون',
        'track_expiry' => 'تتبع الصلاحية',
        'description_ar' => 'الوصف بالعربية',
        'description_en' => 'الوصف بالإنجليزية',
        'minimum_stock' => 'الحد الأدنى للمخزون',
        'suggested_purchase_cost' => 'تكلفة الشراء المقترحة',
        'suggested_sale_price' => 'سعر البيع المقترح',
        'warehouse_id' => 'المستودع',
        'source_warehouse_id' => 'مستودع المصدر',
        'destination_warehouse_id' => 'مستودع الوجهة',
        'batch_number' => 'رقم الدفعة',
        'expiry_date' => 'تاريخ الصلاحية',
        'quantity' => 'الكمية',
        'unit_price' => 'سعر الوحدة',
        'cost_price' => 'سعر التكلفة',

        // Parties (Vendors / Customers)
        'vendor_id' => 'المورد',
        'customer_id' => 'العميل',
        'opening_balance' => 'الرصيد الافتتاحي',
        'credit_limit' => 'سقف الائتمان',
        'payment_terms_days' => 'مدة السداد (بالأيام)',
        'address' => 'العنوان',

        // Documents (Sales / Purchasing)
        'document_date' => 'تاريخ المستند',
        'issue_date' => 'تاريخ الإصدار',
        'due_date' => 'تاريخ الاستحقاق',
        'payment_date' => 'تاريخ الدفع',
        'event_date' => 'تاريخ الحركة',
        'eventDate' => 'تاريخ الحركة',
        'reference' => 'المرجع',
        'reference_number' => 'رقم المرجع',
        'notes' => 'الملاحظات',
        'terms' => 'الشروط والأحكام',
        'subtotal' => 'المجموع الفرعي',
        'tax_rate' => 'نسبة الضريبة',
        'tax_amount' => 'قيمة الضريبة',
        'discount' => 'الخصم',
        'discount_type' => 'نوع الخصم',
        'discount_amount' => 'قيمة الخصم',
        'total' => 'الإجمالي',
        'grand_total' => 'الإجمالي الكلي',
        'amount' => 'المبلغ',
        'paid_amount' => 'المبلغ المدفوع',

        // Treasury & Money
        'account_id' => 'الحساب المالي',
        'from_account_id' => 'الحساب المحول منه',
        'to_account_id' => 'الحساب المحول إليه',
        'check_number' => 'رقم الشيك',
        'bank_name' => 'اسم البنك',
        'drawer_name' => 'اسم الساحب',
        'beneficiary_name' => 'اسم المستفيد',

        // HR & Payroll
        'employee_id' => 'الموظف',
        'basic_salary' => 'الراتب الأساسي',
        'allowance' => 'البدلات',
        'deduction' => 'الخصومات',
        'net_salary' => 'صافي الراتب',
        'advance_amount' => 'مبلغ السلفة',
        'repayment_months' => 'أشهر السداد',

        // Document Sequence & Settings
        'prefix' => 'البادئة',
        'next_number' => 'الرقم التالي',
        'logo' => 'الشعار',
    ],

];
