<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
  xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
  xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9"
  xmlns:xhtml="http://www.w3.org/1999/xhtml"
  exclude-result-prefixes="sm xhtml">
  <xsl:output method="html" encoding="UTF-8"/>
  <xsl:template match="/">
    <html lang="fr">
      <head>
        <meta charset="UTF-8"/>
        <meta name="viewport" content="width=device-width, initial-scale=1"/>
        <title>Sitemap XML — LNSTRADE</title>
        <style>
          body{margin:0;background:#f5f7fb;color:#172033;font:15px/1.5 system-ui,sans-serif}main{max-width:1180px;margin:48px auto;padding:0 24px}h1{margin:0 0 8px;font-size:30px}p{margin:0 0 28px;color:#586174}.card{overflow:auto;border:1px solid #dfe3eb;border-radius:14px;background:#fff;box-shadow:0 8px 24px rgba(23,32,51,.06)}table{width:100%;border-collapse:collapse}th,td{padding:14px 16px;border-bottom:1px solid #edf0f5;text-align:left;vertical-align:top}th{background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:.06em}a{color:#2458d3;text-decoration:none}a:hover{text-decoration:underline}.lang{display:inline-block;margin:2px 8px 2px 0;padding:2px 7px;border-radius:999px;background:#edf3ff;font-size:12px}
        </style>
      </head>
      <body>
        <main>
          <h1>Sitemap XML de LNSTRADE</h1>
          <p><xsl:value-of select="count(sm:urlset/sm:url)"/> URLs canoniques disponibles pour les moteurs de recherche.</p>
          <div class="card">
            <table>
              <thead><tr><th>URL</th><th>Dernière modification</th><th>Versions linguistiques</th></tr></thead>
              <tbody>
                <xsl:for-each select="sm:urlset/sm:url">
                  <tr>
                    <td><a href="{sm:loc}"><xsl:value-of select="sm:loc"/></a></td>
                    <td><xsl:value-of select="sm:lastmod"/></td>
                    <td>
                      <xsl:for-each select="xhtml:link">
                        <a class="lang" href="{@href}"><xsl:value-of select="@hreflang"/></a>
                      </xsl:for-each>
                    </td>
                  </tr>
                </xsl:for-each>
              </tbody>
            </table>
          </div>
        </main>
      </body>
    </html>
  </xsl:template>
</xsl:stylesheet>
