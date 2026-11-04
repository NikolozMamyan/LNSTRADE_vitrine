import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['net', 'gross', 'rate', 'pcb', 'basis', 'cartonNet', 'cartonTax', 'cartonGross'];

  connect() { this.calculate(); }
  netInput() { this.basisTarget.value = 'ht'; this.calculate(); }
  grossInput() { this.basisTarget.value = 'ttc'; this.calculate(); }

  calculate() {
    try {
      const rate = this.amount(this.rateTarget.value, 2);
      if (rate > 10000n) throw new Error('Invalid VAT');
      let net;
      if (this.basisTarget.value === 'ttc') {
        net = this.round(this.amount(this.grossTarget.value, 6), 10000n, 10000n + rate);
        this.netTarget.value = this.decimal(net);
      } else {
        net = this.amount(this.netTarget.value, 6);
        this.grossTarget.value = this.decimal(this.round(net, 10000n + rate, 10000n));
      }
      if (!/^\d{1,5}$/.test(this.pcbTarget.value)) throw new Error('Missing case size');
      const pcb = BigInt(this.pcbTarget.value);
      if (pcb < 1n || pcb > 10000n) throw new Error('Invalid case size');
      const carton = this.round(net * pcb, 1n, 10000n);
      const tax = this.round(carton, rate, 10000n);
      const money = value => new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(Number(value) / 100);
      this.cartonNetTarget.textContent = money(carton);
      this.cartonTaxTarget.textContent = money(tax);
      this.cartonGrossTarget.textContent = money(carton + tax);
    } catch {
      this.cartonNetTarget.textContent = this.cartonTaxTarget.textContent = this.cartonGrossTarget.textContent = '—';
    }
  }

  amount(value, decimals) {
    value = value.trim().replace(',', '.');
    if (!new RegExp(`^\\d{1,8}(?:\\.\\d{1,${decimals}})?$`).test(value)) throw new Error('Invalid amount');
    const [integer, fraction = ''] = value.split('.');
    return BigInt(integer + fraction.padEnd(decimals, '0'));
  }

  round(value, numerator, denominator) { return (value * numerator + denominator / 2n) / denominator; }
  decimal(value) { return `${value / 1000000n}.${String(value % 1000000n).padStart(6, '0')}`; }
}
