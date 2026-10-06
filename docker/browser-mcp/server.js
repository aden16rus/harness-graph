import http from 'http';
import puppeteer from 'puppeteer-core';

const PORT = parseInt(process.env.PORT || '3000', 10);
const CHROME_PATH = process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium';

async function browseUrl({ url, wait_selector, extract_html = false, timeout_ms = 30000 }) {
  if (!url) {
    throw new Error('URL parameter is required');
  }

  if (!/^https?:\/\//i.test(url)) {
    url = 'https://' + url;
  }

  const browser = await puppeteer.launch({
    executablePath: CHROME_PATH,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--no-first-run'
    ]
  });

  try {
    const page = await browser.newPage();
    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36');
    await page.setViewport({ width: 1280, height: 800 });

    const timeout = Math.min(Math.max(timeout_ms || 30000, 5000), 60000);
    const resp = await page.goto(url, {
      waitUntil: 'domcontentloaded',
      timeout
    }).catch(() => null);

    if (wait_selector) {
      await page.waitForSelector(wait_selector, { timeout: 10000 }).catch(() => null);
    }

    const title = await page.title();
    const finalUrl = page.url();
    const statusCode = resp ? resp.status() : 200;

    const extracted = await page.evaluate((needHtml) => {
      if (needHtml) {
        return document.documentElement.outerHTML;
      }
      const clone = (document.body || document.documentElement).cloneNode(true);
      const unwanted = clone.querySelectorAll('script, style, noscript, svg, iframe');
      unwanted.forEach(el => el.remove());
      const text = clone.innerText || clone.textContent || '';
      return text.replace(/\n{3,}/g, '\n\n').trim();
    }, extract_html);

    const links = await page.evaluate(() => {
      const anchors = Array.from(document.querySelectorAll('a[href]'));
      return anchors
        .map(a => ({ text: a.textContent.trim(), href: a.href }))
        .filter(a => a.href && a.href.startsWith('http'))
        .slice(0, 25);
    }).catch(() => []);

    return {
      success: true,
      url: finalUrl,
      title: title || 'No title',
      status: statusCode,
      content: (extracted || '').slice(0, 30000),
      content_length: (extracted || '').length,
      links
    };
  } finally {
    await browser.close().catch(() => null);
  }
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`);

  if (req.method === 'GET' && url.pathname === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok', service: 'browser-mcp', browser: 'chromium' }));
    return;
  }

  // Direct browse endpoint
  if (req.method === 'POST' && (url.pathname === '/browse' || url.pathname === '/api/browse')) {
    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', async () => {
      try {
        const params = JSON.parse(body || '{}');
        const result = await browseUrl(params);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(result));
      } catch (err) {
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: false, error: err.message }));
      }
    });
    return;
  }

  // Model Context Protocol (MCP) JSON-RPC 2.0 endpoint
  if (req.method === 'POST' && url.pathname === '/mcp') {
    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', async () => {
      try {
        const rpc = JSON.parse(body || '{}');
        const { id, method, params } = rpc;

        if (method === 'initialize') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            jsonrpc: '2.0',
            id,
            result: {
              protocolVersion: '2024-11-05',
              capabilities: {
                tools: {}
              },
              serverInfo: {
                name: 'browser-mcp',
                version: '1.0.0'
              }
            }
          }));
          return;
        }

        if (method === 'notifications/initialized') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ jsonrpc: '2.0', id }));
          return;
        }

        if (method === 'tools/list') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            jsonrpc: '2.0',
            id,
            result: {
              tools: [
                {
                  name: 'browse_link',
                  description: 'Opens a web URL in isolated Chromium browser container (MCP), renders client-side JS, and extracts text/title and links',
                  inputSchema: {
                    type: 'object',
                    properties: {
                      url: { type: 'string', description: 'Web page URL to navigate to' },
                      wait_selector: { type: 'string', description: 'CSS selector to wait for' },
                      extract_html: { type: 'boolean', description: 'Extract full HTML if true' }
                    },
                    required: ['url']
                  }
                },
                {
                  name: 'open_link',
                  description: 'Alias for browse_link: opens URL in browser and returns rendered content',
                  inputSchema: {
                    type: 'object',
                    properties: {
                      url: { type: 'string', description: 'Web page URL to navigate to' }
                    },
                    required: ['url']
                  }
                }
              ]
            }
          }));
          return;
        }

        if (method === 'tools/call') {
          const toolName = params?.name;
          const args = params?.arguments || {};
          if (toolName === 'browse_link' || toolName === 'open_link' || toolName === 'browser_open') {
            const data = await browseUrl(args);
            let linksText = '';
            if (Array.isArray(data.links) && data.links.length > 0) {
              linksText = '\n\nFound Links:\n' + data.links.slice(0, 20).map(l => `- [${l.text || 'Link'}](${l.href})`).join('\n');
            }
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({
              jsonrpc: '2.0',
              id,
              result: {
                content: [
                  {
                    type: 'text',
                    text: `Title: ${data.title}\nURL: ${data.url}\nStatus: ${data.status}\n\nContent:\n${data.content}${linksText}`
                  }
                ],
                meta: {
                  title: data.title,
                  url: data.url,
                  status: data.status,
                  links_count: data.links?.length || 0
                }
              }
            }));
            return;
          }
          res.writeHead(400, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ jsonrpc: '2.0', id, error: { code: -32601, message: `Tool ${toolName} not found` } }));
          return;
        }

        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ jsonrpc: '2.0', id, error: { code: -32601, message: `Method ${method} not found` } }));
      } catch (err) {
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ jsonrpc: '2.0', error: { code: -32000, message: err.message } }));
      }
    });
    return;
  }

  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ error: 'Not found' }));
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`[Browser MCP] Server listening on http://0.0.0.0:${PORT}`);
});