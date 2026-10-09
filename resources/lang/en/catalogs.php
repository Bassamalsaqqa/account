<?php

declare(strict_types=1);

return [
    // General & Navigation
    'title' => 'Product Catalog',
    'catalogs' => 'Catalogs',
    'catalog_composer' => 'Catalog Composer',
    'back_to_catalogs' => 'Back to Catalogs',
    'new_catalog' => 'New Catalog',
    'create_catalog' => 'Create Catalog',
    'edit_catalog' => 'Edit Catalog',
    'no_catalogs' => 'No catalogs found.',
    'manage' => 'Manage',
    'revision' => 'Revision',
    'draft_revision' => 'Draft rev: :rev',
    'published_revision' => 'Published rev: :rev',
    'never_published' => 'Never published',
    'published_at' => 'Published at: :date',
    'unsaved_changes' => 'Unsaved draft changes',
    'saved' => 'Saved',

    // Statuses
    'status_draft' => 'Draft',
    'status_active' => 'Active',
    'status_paused' => 'Paused',
    'status_revoked' => 'Revoked',

    // Marketing Media Notice (Option A)
    'media_notice_title' => 'Published product photographs',
    'media_notice_text' => 'Deliberately published product photos are public marketing assets. Pausing or revoking a catalog shuts down managed HTML, QR, and PDF destinations, but copied direct public image URLs or cached images in public storage may remain accessible. Approved media identity is frozen per published revision and cannot be replaced by mutable product primary images without a fresh preview and publish.',

    // Headers & Fields
    'details' => 'Catalog Details',
    'name_ar' => 'Catalog Name (Arabic)',
    'name_en' => 'Catalog Name (English)',
    'description_ar' => 'Description (Arabic)',
    'description_en' => 'Description (English)',
    'default_locale' => 'Default Catalog Language',
    'locale_ar' => 'Arabic',
    'locale_en' => 'English',
    'display_options' => 'Display Options',
    'show_images' => 'Show Product Images',
    'show_sku' => 'Show Product SKU',
    'show_description' => 'Show Product Description',
    'show_prices' => 'Show Selling Prices',

    // Pricing & Tax Basis
    'pricing_settings' => 'Pricing Settings',
    'currency' => 'Currency',
    'select_currency' => 'Select authorized currency',
    'tax_basis' => 'Price Tax Basis',
    'tax_basis_placeholder' => 'e.g. Prices include 16% VAT',
    'price_ack_title' => 'Pricing Acknowledgment',
    'price_ack_label' => 'I explicitly acknowledge the specified selling prices, tax basis, and currency for this catalog prior to public publication.',
    'price_on_request' => 'Price on request',
    'price_on_request_short' => 'On request',

    // Products & Selection
    'products' => 'Catalog Products',
    'selected_products' => 'Selected Products',
    'selected_count' => ':count of :max products selected',
    'no_products_selected' => 'No products selected yet. Search products below to add them to this catalog.',
    'search_products' => 'Search company products by name or SKU...',
    'add_product' => 'Add to Catalog',
    'already_added' => 'Already added',
    'product_limit_reached' => 'Maximum limit of 250 products reached',
    'unit' => 'Unit',
    'image' => 'Display Photo',
    'no_image' => 'No image',
    'custom_name_ar' => 'Custom Name (Arabic)',
    'custom_name_en' => 'Custom Name (English)',
    'custom_description_ar' => 'Custom Description (Arabic)',
    'custom_description_en' => 'Custom Description (English)',
    'custom_price' => 'Custom Selling Price',
    'default_from_product' => 'Default from product master',

    // Reorder & Removal
    'move_up' => 'Move Up',
    'move_down' => 'Move Down',
    'remove' => 'Remove from Catalog',

    // Actions
    'save_draft' => 'Save Draft',
    'preview' => 'Preview Publication',
    'publish' => 'Publish Catalog',
    'pause' => 'Pause Catalog',
    'resume' => 'Resume Publication',
    'revoke' => 'Revoke Link',
    'share_link' => 'Share Link',
    'download_pdf' => 'Download PDF',
    'print' => 'Print',
    'close' => 'Close',

    // Share & Delivery
    'share_title' => 'Share Product Catalog',
    'share_url' => 'Public Catalog Link',
    'copy_link' => 'Copy Link',
    'link_copied' => 'Link copied to clipboard',
    'share_whatsapp' => 'Share via WhatsApp',
    'share_email' => 'Share via Email',
    'native_share' => 'Share via Device',
    'qr_code' => 'QR Code',
    'show_qr' => 'Show QR Code',
    'scan_qr_instruction' => 'Scan QR code to open catalog on a mobile device',
    'security_settings' => 'Access Settings (initial creation only)',
    'password_optional' => 'Password (optional, 8-128 characters)',
    'expiry_date' => 'Link Expiry Date (optional)',
    'create_link' => 'Create Public Link',
    'recovering_existing' => 'Existing stable link recovered. Previous security settings remain active.',

    // Public Viewer Page
    'public_view_title' => 'Public Product Catalog',
    'catalog_unavailable_title' => 'Catalog Unavailable',
    'catalog_unavailable_desc' => 'Sorry, this catalog is paused, expired, or has been revoked by the company.',
    'password_required_title' => 'Password Required',
    'password_required_desc' => 'Please enter the access password to view this catalog.',
    'enter_password' => 'Enter password',
    'unlock_catalog' => 'View Catalog',
    'switch_language' => 'العربية',
    'page_info' => 'Page :current of :total',
    'all_rights_reserved' => 'All rights reserved.',

    // Notifications & Errors
    'draft_saved' => 'Catalog draft saved successfully.',
    'published_successfully' => 'Catalog published successfully.',
    'state_updated' => 'Catalog state updated successfully.',
    'link_created' => 'Public link created successfully.',
    'stale_preview_error' => 'Catalog content changed since last preview. Please preview again before publishing.',
    'price_ack_required' => 'Priced catalog publication requires explicit price acknowledgment, currency, and tax basis.',
    'unexpected_error' => 'An unexpected error occurred while processing the request.',
    'invalid_password' => 'Incorrect password.',
    'access_denied' => 'You are not authorized to perform this action.',
];
