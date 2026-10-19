// Downloads only a fully prepared private ZIP. No business data enters Livewire state.
export function companyDataDownload(config = {}) {
    let controller = null;
    let generation = 0;
    return {
        preparing: false, needsConfirmation: false, error: '', message: '',
        destroy() { generation++; controller?.abort(); },
        async download(form) {
            if (this.preparing) return;
            const version = generation;
            this.preparing = true;
            this.error = '';
            this.needsConfirmation = false;
            this.message = config.preparing || '';
            controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 90000);
            try {
                const response = await fetch(form.action, {
                    method: 'POST', body: new FormData(form), credentials: 'same-origin', cache: 'no-store',
                    // Laravel password.confirm negotiates its 423 response from
                    // the preferred Accept type; successful downloads remain ZIPs.
                    headers: {Accept:'application/json, application/zip', 'X-Requested-With':'XMLHttpRequest'},
                    signal: controller.signal,
                });
                if (version !== generation) return;
                if (response.status === 423) {
                    this.needsConfirmation = true;
                    this.message = '';
                    this.error = config.confirmRequired || config.failed;
                    return;
                }
                if (!response.ok || !response.headers.get('content-type')?.includes('application/zip')) {
                    const content = response.headers.get('content-type')?.includes('application/json')
                        ? await response.json().catch(()=>null) : null;
                    throw new Error(content?.message || config.failed);
                }
                const maximum = 52_428_800;
                const expected = Number(response.headers.get('content-length'));
                if (!Number.isSafeInteger(expected) || expected <= 0 || expected > maximum) throw new Error(config.failed);
                const reader = response.body.getReader();
                const chunks = [];
                let bytes = 0;
                while (true) {
                    const {done, value} = await reader.read();
                    if (done) break;
                    bytes += value.byteLength;
                    if (bytes > maximum || bytes > expected) {
                        await reader.cancel();
                        throw new Error(config.failed);
                    }
                    chunks.push(value);
                }
                if (version !== generation) return;
                if (bytes !== expected) throw new Error(config.failed);
                const zip = new Blob(chunks, {type:'application/zip'});
                const url = URL.createObjectURL(zip);
                const anchor = document.createElement('a');
                anchor.href = url;
                anchor.download = 'company-data.zip';
                document.body.appendChild(anchor);
                anchor.click();
                anchor.remove();
                setTimeout(()=>URL.revokeObjectURL(url), 1000);
                this.message = config.started || '';
            } catch (error) {
                if (version === generation) {
                    this.message = '';
                    this.error = config.failed || error.message;
                }
            } finally {
                clearTimeout(timeout);
                if (version === generation) this.preparing = false;
                controller = null;
            }
        },
    };
}

if (typeof window !== 'undefined') {
    const register = () => window.Alpine?.data('companyDataDownload', companyDataDownload);
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, {once:true});
}
