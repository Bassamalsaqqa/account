class SalesPcmCapture extends AudioWorkletProcessor {
    constructor() {
        super();
        this.buffer = new Float32Array(4096);
        this.used = 0;
        this.port.onmessage = (event) => {
            if (event.data === 'flush') {
                if (this.used) this.port.postMessage({ samples: this.buffer.slice(0, this.used) });
                this.used = 0;
                this.port.postMessage({ flushed: true });
            }
        };
    }

    process(inputs) {
        const samples = inputs[0]?.[0];
        if (!samples) return true;
        for (const sample of samples) {
            this.buffer[this.used++] = sample;
            if (this.used === this.buffer.length) {
                this.port.postMessage({ samples: this.buffer.slice() });
                this.used = 0;
            }
        }
        return true;
    }
}

registerProcessor('sales-pcm-capture', SalesPcmCapture);
