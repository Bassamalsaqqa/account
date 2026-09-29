<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDocumentSettings extends Model
{
    use BelongsToCompany;

    /**
     * @var string
     */
    protected $primaryKey = 'company_id';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'default_document_locale',
        'show_logo',
        'show_qr_by_default',
        'show_product_images_on_quotes',
        'invoice_footer_ar',
        'invoice_footer_en',
        'quotation_terms_ar',
        'quotation_terms_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_logo' => 'boolean',
            'show_qr_by_default' => 'boolean',
            'show_product_images_on_quotes' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
