<?php

return [
    'title' => 'Owner Impersonation',
    'help' => 'Act as this business’s active Owner for up to 15 minutes under audited administrative delegation. Operational changes and financial transactions are recorded with operator provenance.',
    'start' => 'Start Impersonation Session',
    'reason' => 'Session reason or authorization ticket',
    'reason_help' => 'Briefly describe the operational purpose. Do not enter passwords, private customer secrets or financial figures.',
    'password' => 'Your current operator password',
    'permission' => 'Impersonation access has not been granted to this operator account.',
    'banner' => 'Administrative Impersonation: :company',
    'acting' => 'Operator :operator acting as :owner. Session active until :time.',
    'read_only' => 'Administrative impersonation session. Return to the operator account to end this session.',
    'exit' => 'Exit Impersonation',
    'expired' => 'The impersonation session ended or its authorization expired. Your operator account is unchanged.',
    'already_active' => 'End the current impersonation session before starting another.',
    'owner_unavailable' => 'This business needs exactly one active Owner for impersonation access.',
    'disabled_controls' => 'Impersonation mode active.',
    'audit' => 'Impersonation Context',
];
