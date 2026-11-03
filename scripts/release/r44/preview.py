"""Serve immutable R44 frontend artifacts on loopback for fixed-candidate review.

Only local APIs/uploads are proxied. This server never rewrites a generated
asset, switches a shared development service, or connects to production.
"""
import http.client
import http.server
import json
import mimetypes
import pathlib
import urllib.parse

ROOT = pathlib.Path(__file__).resolve().parents[3]
EVIDENCE = ROOT.parent / '任务管理/进行中/第44轮-拼团大屏与推广/发布核对'
ADMIN = pathlib.Path('/private/tmp/mohe-r44-release-ebd40c349759/candidate-admin-dist')
def cashier_output():
    """Follow the fixed build receipt, never a mutable development output."""
    plan = json.loads((EVIDENCE / 'trees.json').read_text())
    receipt = plan.get('frontendBuilds', {}).get('cashier', '6fe698739f31-candidate-cashier-v3-build.json')
    build = json.loads((EVIDENCE / receipt).read_text())
    assert build['result'] == 'PASS' and build['sourceStable']
    return pathlib.Path(build['output'])


class Handler(http.server.BaseHTTPRequestHandler):
    def handle_request(self):
        """Preserve request semantics while keeping all API traffic on localhost."""
        parsed = urllib.parse.urlsplit(self.path)
        route = urllib.parse.unquote(parsed.path)
        if route.startswith(('/adminapi/', '/api/', '/storeapi/', '/uploads/', '/upload/')):
            connection = http.client.HTTPConnection('127.0.0.1', 8080, timeout=60)
            headers = {k: v for k, v in self.headers.items() if k.lower() not in ('host', 'connection')}
            body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
            connection.request(self.command, self.path, body, headers)
            response = connection.getresponse()
            data = response.read()
            self.send_response(response.status)
            for key, value in response.getheaders():
                if key.lower() not in ('connection', 'transfer-encoding', 'content-length'):
                    self.send_header(key, value)
            self.send_header('Content-Length', str(len(data)))
            self.end_headers()
            self.wfile.write(data)
            connection.close()
            return
        if self.command != 'GET':
            self.send_error(405)
            return
        if route.startswith('/view_cashier_v3/'):
            base, relative = cashier_output(), route.removeprefix('/view_cashier_v3/') or 'index.html'
        else:
            base, relative = ADMIN, ('system.html' if route == '/' or route.startswith('/admin/') else route.lstrip('/'))
        target = (base / relative).resolve()
        if not target.is_relative_to(base) or not target.is_file():
            self.send_error(404)
            return
        data = target.read_bytes()
        self.send_response(200)
        self.send_header('Content-Type', mimetypes.guess_type(target)[0] or 'application/octet-stream')
        self.send_header('Content-Length', str(len(data)))
        self.send_header('Cache-Control', 'no-store')
        self.end_headers()
        self.wfile.write(data)

    do_GET = do_POST = do_PUT = do_DELETE = do_PATCH = handle_request

    def log_message(self, *_):
        # Authentication headers and response payloads must not enter evidence.
        pass


if __name__ == '__main__':
    association = json.loads((EVIDENCE / 'admin-build-candidate-association.json').read_text())
    assert association['result'] == 'PASS'
    assert (ADMIN / 'system.html').is_file() and (cashier_output() / 'index.html').is_file()
    print('R44 fixed frontend preview: http://127.0.0.1:18144/admin/marketing/store_combination/dashboard', flush=True)
    http.server.ThreadingHTTPServer(('127.0.0.1', 18144), Handler).serve_forever()
