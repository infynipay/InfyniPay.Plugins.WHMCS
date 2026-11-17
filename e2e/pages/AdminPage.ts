import { Page, expect } from '@playwright/test';
import { cfg } from '../fixtures/config.js';

/**
 * WHMCS admin area: login, activate the InfyniPay gateway, and read order status.
 *
 * NOTE: WHMCS admin markup is fairly stable across versions, but selectors here
 * are best-effort. If your build differs, run `npx playwright codegen <adminURL>`
 * to capture exact selectors and adjust the few marked spots.
 */
export class AdminPage {
  constructor(private readonly page: Page) {}

  async login(): Promise<void> {
    await this.page.goto(`${cfg.whmcs.adminURL}/login.php`, { waitUntil: 'domcontentloaded' });
    await this.page.fill('#username, input[name="username"]', cfg.whmcs.adminUser);
    await this.page.fill('#password, input[name="password"]', cfg.whmcs.adminPass);
    await this.page.click('#login, button[type="submit"], input[type="submit"]');
    // Admin dashboard / sidebar present once logged in.
    await expect(this.page.locator('#header, .navbar, #main-menu')).toBeVisible({ timeout: 30_000 });
  }

  /**
   * Activate the InfyniPay gateway module if it isn't already.
   * Goes to System Settings > Payment Gateways > All Payment Gateways.
   */
  async activateGatewayModule(): Promise<void> {
    await this.page.goto(`${cfg.whmcs.adminURL}/configgateways.php`, { waitUntil: 'domcontentloaded' });

    // The "All Payment Gateways" tab lists every available module with an Activate link.
    const allTab = this.page.getByRole('link', { name: /all payment gateways/i });
    if (await allTab.count()) {
      await allTab.first().click();
    }

    // Already active? The visible gateways table will contain "InfyniPay".
    const activeRow = this.page.locator('tr', { hasText: /infynipay/i });
    if (await activeRow.count()) {
      // Could be in the active list already; nothing to do for module activation.
      return;
    }

    // Otherwise click the Activate control next to InfyniPay in the all-gateways grid.
    // WHMCS renders these as cards/links named after the gateway.
    const activateLink = this.page.getByRole('link', { name: /infynipay/i }).first();
    await activateLink.click();
    await this.page.getByRole('button', { name: /activate/i }).first().click().catch(() => {});
  }

  /** Direct link to the InfyniPay gateway settings page (where the Activate-OAuth button lives). */
  async openGatewaySettings(): Promise<void> {
    await this.page.goto(`${cfg.whmcs.adminURL}/configgateways.php`, { waitUntil: 'domcontentloaded' });
    const link = this.page.getByRole('link', { name: /infynipay/i }).first();
    if (await link.count()) {
      await link.click();
    }
  }

  /**
   * Read the order status WHMCS recorded for a given invoice via the admin orders list.
   * Used as a UI cross-check of the DB assertion.
   */
  async getOrderStatusForInvoice(invoiceId: number): Promise<string | null> {
    // Orders list filtered — fall back to scanning. Admin order detail is reachable
    // from the invoice page "Related Order" link in most themes.
    await this.page.goto(`${cfg.whmcs.adminURL}/invoices.php?action=edit&id=${invoiceId}`, {
      waitUntil: 'domcontentloaded',
    });
    const statusBadge = this.page.locator('text=/Active|Pending|Cancelled|Fraud/i').first();
    if (await statusBadge.count()) {
      return (await statusBadge.innerText()).trim();
    }
    return null;
  }
}
