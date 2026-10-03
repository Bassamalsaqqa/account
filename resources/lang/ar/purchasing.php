<?php

return [
    // General
    'purchasing' => 'المشتريات',
    'vendors' => 'الموردون',
    'vendor' => 'المورد',
    'purchase_invoices' => 'فواتير المشتريات',
    'purchase_settings' => 'إعدادات المشتريات',

    // Vendor fields
    'vendor_name_ar' => 'اسم المورد (عربي)',
    'vendor_name_en' => 'اسم المورد (إنجليزي)',
    'vendor_code' => 'رقم المورد',
    'vendor_number' => 'رقم المورد',
    'business_name_ar' => 'الاسم التجاري (عربي)',
    'business_name_en' => 'الاسم التجاري (إنجليزي)',
    'tax_number' => 'الرقم الضريبي',
    'phone' => 'رقم الهاتف',
    'whatsapp' => 'واتساب',
    'email' => 'البريد الإلكتروني',
    'address_ar' => 'العنوان (عربي)',
    'address_en' => 'العنوان (إنجليزي)',
    'city_ar' => 'المدينة (عربي)',
    'city_en' => 'المدينة (إنجليزي)',
    'postal_code' => 'الرمز البريدي',
    'country_code' => 'رمز الدولة (حرفان)',
    'preferred_locale' => 'لغة المستندات المفضلة',
    'default_currency' => 'العملة الافتراضية',
    'notes' => 'ملاحظات',
    'status' => 'الحالة',
    'active' => 'نشط',
    'inactive' => 'غير نشط',

    // Identity sections
    'identity_section' => 'الهوية',
    'contact_section' => 'التواصل',
    'address_section' => 'العنوان',
    'preferences_section' => 'التفضيلات',

    // Vendor index
    'add_vendor' => 'إضافة مورد',
    'search_vendors' => 'البحث في الموردين...',
    'all_vendors' => 'جميع الموردين',
    'active_vendors' => 'الموردون النشطون',
    'inactive_vendors' => 'الموردون غير النشطين',
    'no_vendors_found' => 'لا يوجد موردون.',
    'no_vendors_match' => 'لا يوجد موردون يطابقون البحث.',

    // Vendor form
    'create_vendor' => 'إضافة مورد جديد',
    'edit_vendor' => 'تعديل بيانات المورد',
    'save_vendor' => 'حفظ المورد',

    // Vendor detail
    'vendor_details' => 'تفاصيل المورد',
    'edit_vendor_button' => 'تعديل',

    // Purchase settings
    'default_payment_terms_days' => 'شروط الدفع الافتراضية (أيام)',
    'default_receiving_warehouse' => 'مستودع الاستلام الافتراضي',
    'warn_duplicate_vendor_invoice' => 'التنبيه عند تكرار رقم فاتورة المورد',
    'warn_duplicate_vendor_invoice_hint' => 'يُظهر تحذيراً عند إدخال رقم فاتورة مورد مستخدم من قبل.',
    'no_default_warehouse' => 'بدون مستودع افتراضي',
    'save_settings' => 'حفظ الإعدادات',

    // Tax
    'sales_tax_account' => 'حساب ضريبة المبيعات / ضريبة المخرجات',
    'purchase_tax_account' => 'حساب ضريبة المدخلات / ضريبة المشتريات',
    'purchase_tax_account_hint' => 'اختر حساباً لضريبة المدخلات القابلة للاسترداد. اتركه فارغاً للضريبة المضافة إلى تكلفة الشراء.',
    'input_tax_account_label' => 'حساب ضريبة المدخلات',

    // Sequences
    'purchase_sequence_type' => 'فواتير المشتريات',
    'purchase_return_sequence_type' => 'مردودات المشتريات',
    'vendor_payment_sequence_type' => 'سندات الدفع للموردين',

    // Success / error messages
    'created_successfully' => 'تم إضافة المورد بنجاح.',
    'updated_successfully' => 'تم تحديث بيانات المورد بنجاح.',
    'settings_saved_successfully' => 'تم حفظ إعدادات المشتريات بنجاح.',
    'invalid_warehouse' => 'المستودع المحدد غير صالح أو لا ينتمي لهذه الشركة.',
    'invalid_purchase_tax_account' => 'حساب ضريبة المدخلات غير صالح أو خارج التسلسل الهرمي المطلوب.',
    'back' => 'رجوع',
    'vendor_directory_hint' => 'بيانات الموردين ووسائل التواصل وتفضيلات الشراء.',
    'company_default' => 'استخدام إعداد الشركة الافتراضي',
    'language_ar' => 'العربية',
    'language_en' => 'الإنجليزية',
    'no_notes' => 'لا توجد ملاحظات.',
    'not_configured' => 'غير محدد',
    'payment_terms_hint' => 'اترك الحقل فارغاً إذا لم تُحدد مدة افتراضية. المدة من 0 إلى 3650 يوماً.',
];
