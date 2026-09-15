/**
 * Cloudflare Worker: serves images from the R2 bucket over a (free) *.workers.dev URL,
 * instead of the rate-limited pub-*.r2.dev public URL that made images intermittently
 * fail to load under sustained traffic.
 *
 * The app builds image URLs as  AWS_URL + "/" + <stored path>  (e.g.
 * https://<worker>.<subdomain>.workers.dev/animals/abc.jpg). This Worker takes the path,
 * reads that object from the bound R2 bucket, and returns it with long-lived cache headers
 * so Cloudflare's edge caches it and repeat views don't re-hit the bucket.
 *
 * --- One-time deploy (Cloudflare dashboard, no CLI needed) ---
 * 1. Workers & Pages -> Create -> Worker. Name it e.g. "secaspi-images". Deploy the starter.
 *    (First time, Cloudflare asks you to pick a *.workers.dev subdomain -- accept one.)
 * 2. Open the Worker -> Edit code. Delete the starter and paste this whole file. Deploy.
 * 3. Worker -> Settings -> Bindings -> Add binding -> R2 bucket.
 *    Variable name: BUCKET   Bucket: (select your existing R2 bucket). Save & deploy.
 * 4. Copy the Worker URL: https://secaspi-images.<your-subdomain>.workers.dev
 * 5. In Render, set  AWS_URL=https://secaspi-images.<your-subdomain>.workers.dev
 *    (scheme included, NO trailing slash) and redeploy. No DB migration needed.
 * 6. In the R2 bucket's Public access settings, turn OFF the r2.dev dev URL.
 */

export default {
  async fetch(request, env, ctx) {
    // Only GET/HEAD make sense for serving assets.
    if (request.method !== "GET" && request.method !== "HEAD") {
      return new Response("Method Not Allowed", { status: 405 });
    }

    const url = new URL(request.url);
    // Strip the leading "/" to get the R2 object key, e.g. "animals/abc.jpg".
    const key = decodeURIComponent(url.pathname.slice(1));
    if (!key) return new Response("Not found", { status: 404 });

    // Serve from the edge cache when we can, so repeat views don't invoke a bucket read.
    const cache = caches.default;
    const cached = await cache.match(request);
    if (cached) return cached;

    const object = await env.BUCKET.get(key);
    if (!object || !object.body) {
      return new Response("Not found", { status: 404 });
    }

    const headers = new Headers();
    object.writeHttpMetadata(headers); // content-type etc. from the stored object
    headers.set("etag", object.httpEtag);
    // Images are content-addressed by a random filename, so they never change -> cache hard.
    headers.set("Cache-Control", "public, max-age=31536000, immutable");
    headers.set("Access-Control-Allow-Origin", "*");

    const response = new Response(object.body, { headers });
    // Store a copy in the edge cache without blocking the response.
    ctx.waitUntil(cache.put(request, response.clone()));
    return response;
  },
};
