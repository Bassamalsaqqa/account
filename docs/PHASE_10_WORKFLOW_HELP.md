# Phase 10 workflow help / إرشادات العمل

Baseline: `c74c9a4e0b4135055dbb8ed7d3e3715d2ddba24f`. Guidance describes existing Laravel workflows; it grants no permissions or production authority.

## العربية

- **المسودة والترحيل:** راجع العميل أو المورد والعملة وسعر الصرف والوحدة والضريبة والخصم قبل الترحيل. المسودة قابلة للتعديل؛ بعد الترحيل تصبح الحركة المالية والمخزنية تاريخاً ثابتاً. استخدم مسار المرتجع أو العكس أو الإلغاء المتاح للمستند، ولا تحذف التاريخ المرحّل أو تعدّل قيمه مباشرة. راجع الأثر المعروض قبل تأكيد الإجراء.
- **عروض الأسعار:** إرسال العرض ليس ترحيلاً محاسبياً. تحويل العرض يُنشئ مسودة فاتورة للمراجعة؛ لا تفترض أن التحويل يعني ترحيل الفاتورة. السعر الافتراضي اقتراح قابل للتعديل، وسعر المستند المرحّل محفوظ تاريخياً.
- **القطعة والكرتونة والدفعات:** راجع وحدة السطر ومعامل التحويل قبل الشراء أو البيع. معامل الكرتونة ليس ثابتاً لكل المنتجات. الباركود يحدد المنتج ووحدته؛ كمية السطر تُحوَّل إلى الوحدة الأساسية وفق السياسة المحفوظة. راجع الدفعة والصلاحية عند الأصناف المتتبعة؛ إتلاف المخزون يسجل خسارة ولا يحذف الحركة الأصلية.
- **النقد والبنك والتسوية:** اختر الحساب النقدي أو البنكي والعملة الصحيحة. ميّز مبلغ السند بعملة الحساب عن المبلغ المخصص للمستند بعملته. الدفعة الجزئية أو غير المخصصة لا تُغلق كل فاتورة تلقائياً. راجع التخصيص وسعر الصرف وتواريخ التطبيق والعكس؛ كشف الحساب يحسب الرصيد عند الحدود الزمنية المطلوبة ولكل عملة.
- **المشتريات والتكلفة:** تُوزّع المصروفات الإضافية المؤهلة على مسودة شراء قبل ترحيلها؛ لا تعِد تسعير شراء مرحّل بأثر رجعي. لا تُظهر صلاحية قراءة شراء محدودة التكلفة تفاصيل التكلفة المخفية. سند دفع المورد وكشفه يتطلبان صلاحياتهما المالية؛ دور المشاهد الافتراضي لا يكفي.
- **المصروفات والرواتب:** اختر التصنيف والحساب الصحيحين. السلفة وصرف الراتب وتطبيق السلفة أحداث مختلفة. صلاحية إدارة هوية الموظف ليست تصريحاً لقراءة راتبه. راجع الإلغاء أو العكس قبل تأكيده؛ تاريخ الحركات محفوظ.
- **الطباعة وPDF:** بطاقة الطباعة في الإعدادات تفتح إعدادات المستندات المتاحة لصاحب الصلاحية. اختر العربية أو الإنجليزية من صفحة المستند. تشمل المخرجات عرض السعر والفاتورة والمرتجع وسند القبض وكشف العميل، والشراء ومرتجعه وسند دفع المورد وكشف المورد. مصمم القوالب المتقدم عمل مستقبلي.
- **المشاركة والكتالوجات:** روابط المستندات المالية تُدار من صفحات المستندات، وتخضع للإصدار وكلمة المرور والصلاحية والإلغاء. لا تخلط رابطاً صادراً مع مسودة أو معاينة. الكتالوج ينشر نسخة معتمدة؛ الأسعار لا تظهر إذا كان عرضها معطلاً. إلغاء رابط الكتالوج نهائي لذلك الرابط؛ إنشاء رابط جديد إجراء منفصل. بطاقة المشاركة في الإعدادات تقود للكتالوجات المتاحة؛ لوحة موحدة لسجل المشاركة عمل مستقبلي.
- **استعادة الحساب:** استخدم تدفق إعادة تعيين كلمة المرور المسموح به. اختبارات البريد المحلي تثبت سلوك التطبيق فقط. تسليم بريد الاستعادة الحقيقي واستعادة النسخ الاحتياطية المعزولة وحفظ النسخ والمفاتيح خارج المضيف لم تُثبت تشغيلياً بعد؛ جاهزية العملاء غير مثبتة.

## English

- **Draft and posting:** Check party, currency, FX, Unit, tax and discount before posting. Drafts are editable. Posted financial and stock history is immutable; use the document's supported return, reversal or void workflow and review its financial effect before confirming.
- **Quotations:** Sending a quotation does not post accounting. Conversion creates an invoice draft for review. Suggested prices remain editable; posted document prices and economic snapshots retain historical truth.
- **Piece/carton and lots:** Check the selected Unit and product-specific conversion. A carton is not a universal quantity. Barcode identity includes Product/Unit. Tracked stock follows lot/expiry policy; disposal records a loss rather than deleting original movements.
- **Cash/bank and settlements:** Select the correct account and currency. Distinguish account-currency payment amount from document-currency allocation. Partial/unallocated payments do not automatically settle every invoice. Statements use historical application/reversal dates and separate currencies.
- **Purchasing and cost:** Eligible landed expenses are allocated to a purchase draft before posting. Do not retrospectively reprice posted purchases. Cost-limited document readers receive only authorized redacted projections. Default Viewer permissions do not authorize Vendor Payment or Statement bytes.
- **Expenses/payroll:** Classification, employee advance, salary recognition, payment and advance application have distinct effects. Employee identity access does not grant salary access. Review any supported reversal before confirmation.
- **Print/PDF:** Settings opens existing document settings for authorized users. Use the document page for AR/EN output. Nine shipped outputs are Quotation, Sales Invoice, Sales Return, Customer Receipt, Customer Statement, Purchase, Purchase Return, Vendor Payment and Vendor Statement. Advanced template design remains future work.
- **Sharing/catalogs:** Manage financial links from document pages under issued-document, password, expiry and revocation controls. Catalog publication fixes an approved revision; prices stay absent when disabled. Catalog link revocation is terminal; creating a new link is separate. Settings' Sharing card opens authorized catalogs. A unified sharing history remains future work.
- **Account recovery:** Local fake-mail QA proves application behavior only. Real password-reset delivery, isolated restoration and independent off-host backup/key recovery remain unverified operational gates. Customer readiness is not established.

## Controlling references

[Master product scope](SMALL_TRADER_ACCOUNTING_MASTER_SPEC_v1.0.md), [engineering boundaries](ENGINEERING_BLUEPRINT_v1.0.md), [accepted decisions](adr/), [Phase 10 execution contract](PHASE_10_AUTONOMOUS_IMPLEMENTATION_PACKAGES.md), and the independently verified source in the implementation PR. UI permissions and server authorization control every actual action; this help does not widen them.
