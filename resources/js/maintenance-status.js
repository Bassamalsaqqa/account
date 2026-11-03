// Network failures also appear as HTTP 503 in Livewire's failure hook.
// Only the prerendered deployment page identifies an intentional upgrade.
export function isUpgradeResponse(status, content) {
    return status === 503 && typeof content === 'string'
        && content.includes('data-accounting-upgrade="true"');
}
