"""Capture screenshots of BF10 app pages for user manuals."""
import asyncio
from playwright.async_api import async_playwright
import os

BASE = "http://localhost:8080"
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "img")
os.makedirs(OUT, exist_ok=True)

MOBILE = {"width": 390, "height": 844, "device_scale_factor": 2, "is_mobile": True}
DESKTOP = {"width": 1280, "height": 900, "device_scale_factor": 1}


async def screenshot(page, name, full_page=True):
    path = os.path.join(OUT, f"{name}.png")
    await page.screenshot(path=path, full_page=full_page)
    print(f"  OK {name}.png")


async def main():
    async with async_playwright() as p:
        browser = await p.chromium.launch()

        # ═══ AVISADOR (mobile) ═══
        print("=== AVISADOR ===")
        ctx = await browser.new_context(viewport=MOBILE)
        page = await ctx.new_page()

        await page.goto(f"{BASE}/avisador/")
        await page.wait_for_timeout(500)

        # Show form screen directly (appScreen)
        await page.evaluate("""() => {
            document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
            var appScreen = document.getElementById('appScreen');
            if (appScreen) appScreen.classList.add('active');
            document.getElementById('userName').textContent = 'PEDRO';
        }""")
        await page.wait_for_timeout(300)
        await screenshot(page, "avisador_01_form")

        # Form filled with sample data
        await page.evaluate("""() => {
            document.getElementById('av-direccion').value = 'Calle Gran Via 28, Madrid';
            document.getElementById('av-sacos').value = '5';
            document.getElementById('av-tlf').value = '612345678';
            document.getElementById('av-observaciones').value = 'Porteria abierta de 9 a 14h';
            var sel = document.getElementById('av-barrio');
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].text === 'Centro') { sel.selectedIndex = i; break; }
            }
            var marca = document.getElementById('av-marca');
            for (var i = 0; i < marca.options.length; i++) {
                if (marca.options[i].value === 'BF10') { marca.selectedIndex = i; break; }
            }
        }""")
        await page.wait_for_timeout(200)
        await screenshot(page, "avisador_02_form_filled")

        # Success toast
        await page.evaluate("""() => {
            var toast = document.querySelector('.toast');
            if (toast) { toast.style.display = 'block'; toast.textContent = '\\u2713 Aviso registrado correctamente'; }
        }""")
        await screenshot(page, "avisador_03_success")

        # History list
        await page.evaluate("""() => {
            var toast = document.querySelector('.toast');
            if (toast) toast.style.display = 'none';
            // Clear form
            document.getElementById('av-direccion').value = '';
            document.getElementById('av-sacos').value = '';
            document.getElementById('av-tlf').value = '';
            document.getElementById('av-observaciones').value = '';
            document.getElementById('av-barrio').selectedIndex = 0;
            document.getElementById('av-marca').selectedIndex = 0;
            // Show history
            var histList = document.getElementById('historyList');
            if (histList) {
                histList.style.display = 'block';
                histList.innerHTML = `
                    <div style="background:#fff;border:1px solid #dde1e6;border-radius:8px;padding:12px;margin-bottom:8px">
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <strong style="font-size:14px">C/ Serrano 45, Madrid</strong>
                            <span style="background:#E8F5E9;color:#2E7D32;padding:2px 8px;border-radius:4px;font-size:11px">recogida</span>
                        </div>
                        <div style="color:#6b7280;font-size:12px;margin-top:4px">5 sacos &middot; Retiro &middot; 10/04/2026</div>
                    </div>
                    <div style="background:#fff;border:1px solid #dde1e6;border-radius:8px;padding:12px;margin-bottom:8px">
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <strong style="font-size:14px">Av. de America 12</strong>
                            <span style="background:#FFF3E0;color:#E65100;padding:2px 8px;border-radius:4px;font-size:11px">por_recoger</span>
                        </div>
                        <div style="color:#6b7280;font-size:12px;margin-top:4px">3 sacos &middot; Salamanca &middot; 11/04/2026</div>
                    </div>
                    <div style="background:#fff;border:1px solid #dde1e6;border-radius:8px;padding:12px;margin-bottom:8px">
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <strong style="font-size:14px">C/ Alcala 100</strong>
                            <span style="background:#FFEBEE;color:#C62828;padding:2px 8px;border-radius:4px;font-size:11px">no_estan</span>
                        </div>
                        <div style="color:#6b7280;font-size:12px;margin-top:4px">8 sacos &middot; Centro &middot; 09/04/2026</div>
                    </div>`;
            }
        }""")
        await page.wait_for_timeout(200)
        await screenshot(page, "avisador_04_history")

        await ctx.close()

        # ═══ COMERCIAL (mobile) ═══
        print("=== COMERCIAL ===")
        ctx = await browser.new_context(viewport=MOBILE)
        page = await ctx.new_page()

        await page.goto(f"{BASE}/comercial.html")
        await page.wait_for_timeout(500)
        # Unregister SW to get fresh content
        await page.evaluate("navigator.serviceWorker.getRegistrations().then(rs => rs.forEach(r => r.unregister()))")
        await page.reload()
        await page.wait_for_timeout(500)

        # Dashboard with fake data (skip login screen)
        await page.evaluate("""() => {
            document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
            var main = document.getElementById('mainScreen');
            if (main) main.classList.add('active');
            document.getElementById('mainName').textContent = 'JUAN';
            document.getElementById('stTotal').textContent = '12';
            document.getElementById('stSacas').textContent = '87';
            document.getElementById('stImporte').innerHTML = '1.450 &euro;';
            document.getElementById('stPagados').textContent = '8/12';
            document.getElementById('albBody').innerHTML = `
                <tr>
                    <td><strong>ALB-2026-0045</strong></td>
                    <td>11/04/2026</td>
                    <td>Reformas Lopez SL</td>
                    <td>BF10</td>
                    <td style="text-align:center;font-weight:700">10</td>
                    <td style="text-align:right;font-weight:700">250,00 &euro;</td>
                    <td style="text-align:center"><span style="background:#E8F5E9;color:#2E7D32;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:600">Si</span></td>
                    <td><button style="font-size:11px;padding:4px 8px;border:1px solid #ccc;border-radius:4px;cursor:pointer">Editar</button></td>
                </tr>
                <tr>
                    <td><strong>ALB-2026-0044</strong></td>
                    <td>10/04/2026</td>
                    <td>Construcciones Perez</td>
                    <td>SERVISACO</td>
                    <td style="text-align:center;font-weight:700">8</td>
                    <td style="text-align:right;font-weight:700">200,00 &euro;</td>
                    <td style="text-align:center"><span style="background:#FFEBEE;color:#C62828;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:600">No</span></td>
                    <td><button style="font-size:11px;padding:4px 8px;border:1px solid #ccc;border-radius:4px;cursor:pointer">Editar</button></td>
                </tr>
                <tr>
                    <td><strong>ALB-2026-0043</strong></td>
                    <td>09/04/2026</td>
                    <td>Maria Garcia</td>
                    <td>ATUSACO</td>
                    <td style="text-align:center;font-weight:700">5</td>
                    <td style="text-align:right;font-weight:700">125,00 &euro;</td>
                    <td style="text-align:center"><span style="background:#E8F5E9;color:#2E7D32;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:600">Si</span></td>
                    <td><button style="font-size:11px;padding:4px 8px;border:1px solid #ccc;border-radius:4px;cursor:pointer">Editar</button></td>
                </tr>`;
        }""")
        await page.wait_for_timeout(300)
        await screenshot(page, "comercial_01_dashboard")

        # Open new albaran modal
        await page.evaluate("""() => {
            var modal = document.getElementById('albModal');
            if (modal) modal.style.display = 'flex';
        }""")
        await page.wait_for_timeout(200)
        await screenshot(page, "comercial_02_nuevo_albaran")

        await ctx.close()

        # ═══ CLIENTE ═══
        print("=== CLIENTE ===")

        # Landing page
        ctx = await browser.new_context(viewport=DESKTOP)
        page = await ctx.new_page()
        await page.goto(f"{BASE}/")
        await page.wait_for_timeout(1000)
        await screenshot(page, "cliente_00_landing", full_page=False)
        await ctx.close()

        # mi-cuenta dashboard
        ctx = await browser.new_context(viewport=DESKTOP)
        page = await ctx.new_page()
        await page.goto(f"{BASE}/mi-cuenta/")
        await page.wait_for_timeout(500)
        await page.evaluate("""() => {
            var login = document.getElementById('login-screen');
            if (login) login.style.display = 'none';
            var dash = document.getElementById('dashboard');
            if (dash) dash.style.display = 'block';
        }""")
        await page.wait_for_timeout(300)
        await screenshot(page, "cliente_01_dashboard")
        await ctx.close()

        # mis-recogidas
        ctx = await browser.new_context(viewport=MOBILE)
        page = await ctx.new_page()
        await page.goto(f"{BASE}/mis-recogidas/")
        await page.wait_for_timeout(500)
        await screenshot(page, "cliente_02_recogidas")
        await ctx.close()

        # Payment confirmation
        ctx = await browser.new_context(viewport=DESKTOP)
        page = await ctx.new_page()
        await page.goto(f"{BASE}/pago-confirmado.html?status=success&code=BF10-2026-0042")
        await page.wait_for_timeout(500)
        await screenshot(page, "cliente_03_pago_ok", full_page=False)
        await ctx.close()

        # Zones section
        ctx = await browser.new_context(viewport=DESKTOP)
        page = await ctx.new_page()
        await page.goto(f"{BASE}/")
        await page.wait_for_timeout(1000)
        await page.evaluate("window.scrollTo(0, document.body.scrollHeight * 0.5)")
        await page.wait_for_timeout(500)
        await screenshot(page, "cliente_04_zonas", full_page=False)
        await ctx.close()

        await browser.close()
        print("\nAll screenshots captured!")


asyncio.run(main())
