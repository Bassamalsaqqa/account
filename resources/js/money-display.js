// Display only. Submitted decimal strings remain untouched.
export function displayMoney(value) {
    const text = String(value ?? '').trim();
    const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(text);
    if (!match) return text;
    const fraction = match[3] ?? '';
    let cents = BigInt(match[2]) * 100n + BigInt((fraction + '00').slice(0, 2));
    if ((fraction[2] ?? '0') >= '5') cents += 1n;
    const digits = cents.toString().padStart(3, '0');
    const whole = digits.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `${match[1] && cents !== 0n ? '-' : ''}${whole}.${digits.slice(-2)}`;
}

if (typeof window !== 'undefined') window.accountingDisplayMoney = displayMoney;
