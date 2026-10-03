<?php

return [
    // General
    'purchasing' => 'Purchasing',
    'vendors' => 'Vendors',
    'vendor' => 'Vendor',
    'purchase_invoices' => 'Purchase Invoices',
    'purchase_settings' => 'Purchase Settings',

    // Vendor fields
    'vendor_name_ar' => 'Vendor Name (Arabic)',
    'vendor_name_en' => 'Vendor Name (English)',
    'vendor_code' => 'Vendor Code',
    'vendor_number' => 'Vendor Number',
    'business_name_ar' => 'Business Name (Arabic)',
    'business_name_en' => 'Business Name (English)',
    'tax_number' => 'Tax Number',
    'phone' => 'Phone',
    'whatsapp' => 'WhatsApp',
    'email' => 'Email',
    'address_ar' => 'Address (Arabic)',
    'address_en' => 'Address (English)',
    'city_ar' => 'City (Arabic)',
    'city_en' => 'City (English)',
    'postal_code' => 'Postal Code',
    'country_code' => 'Country Code (2 letters)',
    'preferred_locale' => 'Preferred Document Language',
    'default_currency' => 'Default Currency',
    'notes' => 'Notes',
    'status' => 'Status',
    'active' => 'Active',
    'inactive' => 'Inactive',

    // Identity sections
    'identity_section' => 'Identity',
    'contact_section' => 'Contact',
    'address_section' => 'Address',
    'preferences_section' => 'Preferences',

    // Vendor index
    'add_vendor' => 'Add Vendor',
    'search_vendors' => 'Search vendors...',
    'all_vendors' => 'All Vendors',
    'active_vendors' => 'Active Vendors',
    'inactive_vendors' => 'Inactive Vendors',
    'no_vendors_found' => 'No vendors found.',
    'no_vendors_match' => 'No vendors match your search.',

    // Vendor form
    'create_vendor' => 'Add New Vendor',
    'edit_vendor' => 'Edit Vendor',
    'save_vendor' => 'Save Vendor',

    // Vendor detail
    'vendor_details' => 'Vendor Details',
    'edit_vendor_button' => 'Edit',

    // Purchase settings
    'default_payment_terms_days' => 'Default Payment Terms (days)',
    'default_receiving_warehouse' => 'Default Receiving Warehouse',
    'warn_duplicate_vendor_invoice' => 'Warn on Duplicate Vendor Invoice Number',
    'warn_duplicate_vendor_invoice_hint' => 'Shows a warning when a vendor invoice number has already been used.',
    'no_default_warehouse' => 'No default warehouse',
    'save_settings' => 'Save Settings',

    // Tax
    'sales_tax_account' => 'Sales / Output Tax Account',
    'purchase_tax_account' => 'Purchase / Input Tax Account',
    'purchase_tax_account_hint' => 'Select an account for recoverable input tax. Leave blank for tax included in purchase cost.',
    'input_tax_account_label' => 'Input Tax Account',

    // Sequences
    'purchase_sequence_type' => 'Purchase Invoices',
    'purchase_return_sequence_type' => 'Purchase Returns',
    'vendor_payment_sequence_type' => 'Vendor Payments',

    // Success / error messages
    'created_successfully' => 'Vendor created successfully.',
    'updated_successfully' => 'Vendor updated successfully.',
    'settings_saved_successfully' => 'Purchase settings saved successfully.',
    'invalid_warehouse' => 'The selected warehouse is invalid or does not belong to this company.',
    'invalid_purchase_tax_account' => 'The purchase tax account is invalid or outside the required hierarchy.',
    'back' => 'Back',
    'vendor_directory_hint' => 'Supplier identity, contact details and purchasing preferences.',
    'company_default' => 'Use company default',
    'language_ar' => 'Arabic',
    'language_en' => 'English',
    'no_notes' => 'No notes.',
    'not_configured' => 'Not configured',
    'payment_terms_hint' => 'Leave blank when no default is agreed. Enter 0–3650 days.',
];
