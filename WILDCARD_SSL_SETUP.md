# Wildcard SSL Certificate Setup Guide

This guide explains how to set up wildcard SSL certificates in Coolify for multi-tenant SaaS applications.

## 🎯 What Are Wildcard SSL Certificates?

Wildcard SSL certificates allow you to secure unlimited subdomains under a single domain with ONE certificate.

### Example:
- **Certificate**: `*.course-app.edesy.in`
- **Covers**:
  - `site1.course-app.edesy.in` ✅
  - `site2.course-app.edesy.in` ✅
  - `site3.course-app.edesy.in` ✅
  - `any-subdomain.course-app.edesy.in` ✅

## 🚀 Benefits for Multi-Tenant SaaS

1. **Instant SSL**: New tenant subdomains get SSL immediately
2. **No Rate Limits**: One certificate for unlimited subdomains
3. **Better Performance**: No need to provision individual certificates
4. **Multi-Level Support**: Works with `*.app.example.com`

---

## 📋 Prerequisites

### 1. DNS Provider Account
You need API access to one of these DNS providers:
- **Cloudflare** (Recommended)
- **AWS Route53**
- **DigitalOcean DNS**

### 2. Domain Configuration
- Your domain must be managed by the DNS provider
- You need DNS API credentials

---

## 🔧 Setup Instructions

### Step 1: Run Database Migration

```bash
php artisan migrate
```

This will add the necessary fields to `server_settings` and `ssl_certificates` tables.

### Step 2: Configure DNS Provider

#### Option A: Cloudflare (Recommended)

1. Log in to [Cloudflare Dashboard](https://dash.cloudflare.com/)
2. Go to **My Profile** → **API Tokens**
3. Click **Create Token**
4. Use template: **Edit zone DNS**
5. Set permissions:
   - **Zone** → **DNS** → **Edit**
6. Select your specific zone (e.g., `example.com`)
7. Copy the API Token

#### Option B: AWS Route53

1. Go to [IAM Console](https://console.aws.amazon.com/iam/)
2. Create new IAM user with **Programmatic access**
3. Attach policy: `AmazonRoute53FullAccess` or create custom policy:
```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "route53:GetChange",
        "route53:ChangeResourceRecordSets",
        "route53:ListResourceRecordSets"
      ],
      "Resource": [
        "arn:aws:route53:::hostedzone/*",
        "arn:aws:route53:::change/*"
      ]
    },
    {
      "Effect": "Allow",
      "Action": "route53:ListHostedZones",
      "Resource": "*"
    }
  ]
}
```
4. Save **Access Key ID** and **Secret Access Key**

#### Option C: DigitalOcean

1. Go to [API Settings](https://cloud.digitalocean.com/account/api/)
2. Generate new **Personal Access Token**
3. Select **Read** and **Write** scopes
4. Copy the token

### Step 3: Configure in Coolify

1. Navigate to **Server Settings** → **Wildcard SSL**
2. Enable **Wildcard SSL**
3. Enter your wildcard domain (e.g., `*.course-app.edesy.in`)
4. Enter your **ACME Email** for Let's Encrypt notifications
5. Select your **DNS Provider**
6. Enter DNS provider credentials:

**For Cloudflare:**
- API Token (recommended), OR
- Email + Global API Key

**For Route53:**
- Access Key ID
- Secret Access Key
- Region

**For DigitalOcean:**
- API Token

7. Click **Test DNS Provider Connection** to verify
8. Click **Save Configuration**

### Step 4: Restart Proxy

After saving, restart your proxy:

```bash
# Via Coolify UI: Server → Proxy → Restart
# Or via command line:
docker restart coolify-proxy
```

### Step 5: Configure Applications

When deploying applications, use domains matching your wildcard:

**Example 1: Course SaaS**
- Application domain: `course-app.edesy.in`
- Tenant 1: `site1.course-app.edesy.in`
- Tenant 2: `site2.course-app.edesy.in`

**Example 2: Blog SaaS**
- Application domain: `blog-app.edesy.in`
- Tenant 1: `myblog.blog-app.edesy.in`
- Tenant 2: `techblog.blog-app.edesy.in`

---

## 🏗️ How It Works

### 1. DNS-01 Challenge Flow

```
1. Traefik requests SSL certificate from Let's Encrypt
2. Let's Encrypt requires proof of domain ownership
3. Traefik creates TXT record: _acme-challenge.example.com
4. Uses DNS Provider API to create the record
5. Let's Encrypt verifies the TXT record exists
6. Certificate is issued and stored in /traefik/acme-dns.json
7. Traefik automatically uses certificate for matching domains
```

### 2. Proxy Configuration

The proxy configuration is automatically updated with:

```yaml
# Traefik Configuration
--certificatesresolvers.letsencrypt-dns.acme.dnschallenge=true
--certificatesresolvers.letsencrypt-dns.acme.dnschallenge.provider=cloudflare
--certificatesresolvers.letsencrypt-dns.acme.email=admin@example.com
--certificatesresolvers.letsencrypt-dns.acme.storage=/traefik/acme-dns.json
```

### 3. Environment Variables

DNS provider credentials are injected as environment variables:

**Cloudflare:**
```bash
CF_API_TOKEN=your-token-here
# OR
CF_API_EMAIL=email@example.com
CF_API_KEY=your-api-key
```

**Route53:**
```bash
AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE
AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
AWS_REGION=us-east-1
```

**DigitalOcean:**
```bash
DO_AUTH_TOKEN=your-token-here
```

---

## 🧪 Testing

### Use Let's Encrypt Staging Server

Before going live, test with the staging server to avoid rate limits:

1. Enable **Use Let's Encrypt Staging Server** in UI
2. Deploy a test application
3. Verify SSL works (you'll see a certificate warning - this is normal)
4. Disable staging mode for production

### Certificate Locations

- **HTTP-01 certificates**: `/traefik/acme.json`
- **DNS-01 certificates**: `/traefik/acme-dns.json`

### Verify Certificate

```bash
# Check if certificate was issued
docker exec coolify-proxy cat /traefik/acme-dns.json | jq '.cloudflare.Certificates'

# Test SSL connection
openssl s_client -connect site1.course-app.edesy.in:443 -servername site1.course-app.edesy.in
```

---

## 🔒 Security Best Practices

1. **Use API Tokens** (not Global API Keys) when possible
2. **Restrict API permissions** to DNS only
3. **Rotate credentials** regularly
4. **Use separate tokens** for production and staging
5. **Monitor Let's Encrypt rate limits**

### Cloudflare Token Permissions

Create a token with minimal permissions:
- **Zone** → **DNS** → **Edit** (for specific zones only)

---

## 🐛 Troubleshooting

### Issue: Certificate Not Issued

**Check Traefik logs:**
```bash
docker logs coolify-proxy
```

**Common errors:**
- `API authentication failed`: Invalid credentials
- `DNS propagation failed`: Wait 2-5 minutes for DNS propagation
- `Rate limit exceeded`: Use staging server or wait 1 week

### Issue: Wildcard Not Working

1. Verify wildcard domain format: `*.example.com` (NOT `*.*.example.com`)
2. Check DNS provider credentials
3. Ensure zone ID is correct (Cloudflare)
4. Restart proxy after configuration changes

### Issue: Slow Certificate Provisioning

DNS-01 challenge takes longer than HTTP-01:
- HTTP-01: ~30 seconds
- DNS-01: 2-5 minutes (waiting for DNS propagation)

### Issue: Multi-Level Subdomains

For `*.*.example.com` patterns:
- Cloudflare Free: ❌ Not supported
- Cloudflare Paid: ✅ Use Advanced Certificate Manager
- Alternative: Use separate wildcard certs per level

---

## 📊 Monitoring & Maintenance

### Certificate Expiration

Certificates auto-renew at 30 days before expiration.

**Check expiration:**
```bash
docker exec coolify-proxy cat /traefik/acme-dns.json | jq '.cloudflare.Certificates[].certificate' | \
openssl x509 -text -noout | grep "Not After"
```

### Logs

**View Traefik logs:**
```bash
docker logs -f coolify-proxy
```

**View Coolify logs:**
```bash
docker logs -f coolify
```

---

## 🚀 Advanced Configuration

### Multiple Wildcard Domains

You can configure multiple wildcard domains:

1. `*.course-app.edesy.in`
2. `*.blog-app.edesy.in`
3. `*.api.edesy.in`

Each will get its own wildcard certificate.

### Custom ACME Server

For enterprise setups with internal CA:

Modify `bootstrap/helpers/proxy.php`:
```php
$config['services']['traefik']['command'][] = '--certificatesresolvers.letsencrypt-dns.acme.caserver=https://your-acme-server.com/directory';
```

---

## 📚 Additional Resources

- [Traefik DNS Challenge](https://doc.traefik.io/traefik/https/acme/#dnschallenge)
- [Let's Encrypt Rate Limits](https://letsencrypt.org/docs/rate-limits/)
- [Cloudflare API Tokens](https://developers.cloudflare.com/fundamentals/api/get-started/create-token/)
- [AWS Route53 API](https://docs.aws.amazon.com/Route53/latest/APIReference/Welcome.html)

---

## 🆘 Support

If you encounter issues:

1. Check Traefik logs: `docker logs coolify-proxy`
2. Verify DNS provider credentials
3. Test with staging server first
4. Check [Coolify Documentation](https://coolify.io/docs)
5. Create an issue on [GitHub](https://github.com/coollabsio/coolify/issues)

---

## ✅ Quick Reference

| Feature | HTTP-01 | DNS-01 (Wildcard) |
|---------|---------|-------------------|
| Certificate Type | Single domain | Wildcard domain |
| Setup Complexity | Easy | Medium |
| DNS API Required | No | Yes |
| Provisioning Time | 30 seconds | 2-5 minutes |
| Multi-level subdomains | Limited | Full support |
| Rate Limits | Per domain | Per wildcard |
| Best For | Individual apps | Multi-tenant SaaS |

---

## 🎉 Success Checklist

- [ ] Database migration completed
- [ ] DNS provider credentials configured
- [ ] Test connection successful
- [ ] Proxy configuration saved and restarted
- [ ] Test application deployed with subdomain
- [ ] SSL certificate issued successfully
- [ ] Browser shows valid SSL (green lock)
- [ ] Multiple subdomains work correctly

---

**You're now ready to use wildcard SSL certificates for your multi-tenant SaaS applications!** 🎊
