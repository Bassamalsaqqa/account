<?php

declare(strict_types=1);

return [
    'title' => 'Company Data Export',
    'subtitle' => 'Download the supported business reference records',
    'description' => 'Export a point-in-time business archive containing company settings, master records, transactions, authoritative currency balances, stock on hand, and general ledger.',
    'download_button' => 'Download Company Data (ZIP)',
    'download_starting' => 'Preparing export archive...',
    'owner_only_badge' => 'Owner Only',
    'unauthorized_owner_only' => 'Only the verified active Company Owner can download company data.',
    'password_confirmation_required' => 'Fresh password confirmation is required before downloading company data.',
    'rate_limited' => 'Too many export requests. Please try again later.',
    'export_in_progress' => 'A data export is already in progress for this company. Please wait for it to complete.',
    'execution_timeout' => 'Export execution time limit exceeded.',
    'row_limit_exceeded' => 'Export exceeded maximum allowed record count.',
    'byte_limit_exceeded' => 'Export archive exceeded maximum allowed size.',
    'memory_limit_exceeded' => 'Export exceeded maximum memory threshold.',
    'authority_revoked' => 'Authorization changed or was revoked during export processing.',
    'session_revoked' => 'Active session is invalid or revoked.',
    'archive_creation_failed' => 'Failed to create export ZIP archive.',
    'audit_summary' => 'Company data export archive prepared for download',
    'zip_requirement_missing' => 'PHP ZipArchive extension is required for company data export.',
];
