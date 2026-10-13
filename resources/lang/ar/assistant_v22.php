<?php

return [
    'clarify_intent' => 'هل تريد عرض معلومات محاسبية أم تجهيز فاتورة غير محفوظة؟ وضّح العملية المطلوبة.',
    'clarify_entity' => 'أي عميل أو مورد أو صنف تقصد؟ اكتب الاسم الكامل أو الرمز.',
    'clarify_entity_missing' => 'لم أجد اسمًا مطابقًا بشكل واضح في هذه الشركة. اكتب الاسم الكامل أو الرمز.',
    'clarify_entity_ambiguous' => 'يوجد أكثر من سجل بهذا الاسم. اكتب الاسم الكامل أو الرمز المميز.',
    'clarify_period' => 'ما الفترة أو التواريخ التي تريدها؟',
    'clarify_currency' => 'بأي عملة من عملات الشركة تريد النتيجة؟',
    'clarify_unsupported' => 'هذا الطلب يحتاج عملية لا يستطيع المساعد تنفيذها بأمان. اختر تقريرًا مدعومًا أو استخدم الشاشة اليدوية.',
    'clarify_question_unsupported' => 'هذا التقرير لا يدعم أحد الشروط المطلوبة. اختر تقريرًا آخر أو وضّح نطاق السؤال.',
    'clarify_question_invalid' => 'وضّح الفترة أو العملة أو النطاق المطلوب حتى أعرض المعلومات من المصدر المحاسبي الصحيح.',
    'comparison' => 'فترة المقارنة',
    'followup' => 'اسأل سؤالًا مكملًا',
    'fields' => [
        'subtotal_currency' => 'المجموع قبل الخصم', 'discount_total_currency' => 'الخصومات', 'tax_total_currency' => 'الضريبة',
        'gross_sales_base' => 'إجمالي المبيعات', 'net_sales_base' => 'صافي المبيعات', 'returns_base' => 'المرتجعات',
        'tax_base' => 'الضريبة', 'discounts_base' => 'الخصومات', 'original_invoice_count' => 'الفواتير',
        'customer_name' => 'العميل', 'customer_code' => 'رمز العميل', 'currency_code' => 'العملة',
        'outstanding_balance' => 'الدين المتبقي', 'outstanding_by_currency' => 'الديون حسب العملة',
        'invoiced_amount' => 'قيمة الفواتير', 'document_number' => 'المستند', 'invoice_number' => 'الفاتورة',
        'issue_date' => 'تاريخ الفاتورة', 'grand_total_currency' => 'إجمالي الفاتورة', 'status' => 'الحالة',
        'product_name' => 'الصنف', 'sku' => 'رمز الصنف', 'unit_code' => 'الوحدة', 'quantity_on_hand' => 'المتوفر',
        'minimum_stock' => 'الحد الأدنى', 'shortage_quantity' => 'النقص', 'stock_status' => 'حالة المخزون',
        'low_stock_count' => 'مخزون منخفض', 'out_of_stock_count' => 'نفد المخزون', 'total_alert_count' => 'التنبيهات',
        'currencies' => 'حسب العملة', 'total_customers' => 'العملاء', 'gross_sales' => 'إجمالي المبيعات', 'net_sales' => 'صافي المبيعات',
    ],
];
