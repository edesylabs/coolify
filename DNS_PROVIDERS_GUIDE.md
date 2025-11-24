# DNS Providers Configuration Guide

Complete setup guide for all supported DNS providers for wildcard SSL certificates.

---

## 📋 **Table of Contents**

1. [Cloudflare Setup](#1-cloudflare-recommended)
2. [AWS Route53 Setup](#2-aws-route53)
3. [DigitalOcean Setup](#3-digitalocean)
4. [Comparison Table](#comparison-table)
5. [Troubleshooting](#troubleshooting)

---

## 1. **Cloudflare** (Recommended)

### Why Cloudflare?
- ✅ Fastest DNS propagation (2-5 minutes)
- ✅ Free tier available
- ✅ Easy API token creation
- ✅ Excellent documentation
- ✅ Global CDN integration

### Prerequisites
- Active Cloudflare account
- Domain added to Cloudflare
- Nameservers pointing to Cloudflare

### Step-by-Step Setup

#### **Option A: API Token (Recommended)**

1. **Log in to Cloudflare Dashboard**
   - Visit: https://dash.cloudflare.com/

2. **Navigate to API Tokens**
   - Click on your profile (top right)
   - Select **My Profile** → **API Tokens**

3. **Create Token**
   - Click **Create Token**
   - Use template: **Edit zone DNS**
   - Or create custom token with:
     - **Permissions**: Zone → DNS → Edit
     - **Zone Resources**: Include → Specific zone → `your-domain.com`

4. **Copy Token**
   - Copy the token immediately (shown only once)
   - Store securely

5. **Configure in Coolify**
   ```
   DNS Provider: Cloudflare
   API Token: [paste your token]
   Zone ID: (optional - auto-detected)
   ```

#### **Option B: Global API Key (Alternative)**

1. **Get Global API Key**
   - Profile → API Tokens → Global API Key → View

2. **Configure in Coolify**
   ```
   DNS Provider: Cloudflare
   Email: your-email@example.com
   Global API Key: [paste key]
   Zone ID: (optional)
   ```

### Finding Your Zone ID (Optional)

1. Go to Cloudflare Dashboard
2. Select your domain
3. Scroll down on Overview page
4. Copy **Zone ID** from right sidebar

### Testing

```bash
# Test with curl
curl -X GET "https://api.cloudflare.com/client/v4/user/tokens/verify" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json"
```

### Rate Limits
- **Free Plan**: 1,200 requests/5 minutes
- **Pro Plan**: 2,400 requests/5 minutes
- Generally sufficient for wildcard SSL

---

## 2. **AWS Route53**

### Why Route53?
- ✅ Enterprise-grade reliability
- ✅ Integrated with AWS ecosystem
- ✅ Advanced routing policies
- ✅ DNSSEC support

### Prerequisites
- AWS Account with billing enabled
- Domain registered or imported to Route53
- IAM user with Route53 permissions

### Step-by-Step Setup

#### **1. Create IAM User**

1. **Go to IAM Console**
   - Visit: https://console.aws.amazon.com/iam/

2. **Create New User**
   - Users → Add users
   - Name: `coolify-dns-acme`
   - Access type: **Programmatic access**

3. **Attach Policy**

   **Option A: Use Managed Policy** (Quick)
   - Select: `AmazonRoute53FullAccess`

   **Option B: Create Custom Policy** (Recommended - Least Privilege)
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
         "Action": [
           "route53:ListHostedZones",
           "route53:ListHostedZonesByName"
         ],
         "Resource": "*"
       }
     ]
   }
   ```

4. **Save Credentials**
   - Copy **Access Key ID**
   - Copy **Secret Access Key**
   - Store securely (shown only once)

#### **2. Get Hosted Zone ID** (Optional)

1. Go to Route53 Console
2. Select **Hosted zones**
3. Click on your domain
4. Copy **Hosted zone ID** (format: Z1234567890ABC)

#### **3. Configure in Coolify**

```
DNS Provider: AWS Route53
Access Key ID: AKIAIOSFODNN7EXAMPLE
Secret Access Key: wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
Region: us-east-1
Hosted Zone ID: (optional - auto-detected)
```

### Testing

```bash
# Test with AWS CLI
aws route53 list-hosted-zones \
  --profile coolify \
  --region us-east-1
```

### IAM Policy Example (Production-Ready)

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "Route53ChangeRecordSets",
      "Effect": "Allow",
      "Action": [
        "route53:ChangeResourceRecordSets"
      ],
      "Resource": [
        "arn:aws:route53:::hostedzone/Z1234567890ABC"
      ]
    },
    {
      "Sid": "Route53ListZones",
      "Effect": "Allow",
      "Action": [
        "route53:ListHostedZones",
        "route53:ListResourceRecordSets",
        "route53:GetChange"
      ],
      "Resource": "*"
    }
  ]
}
```

Replace `Z1234567890ABC` with your actual Hosted Zone ID.

### Cost
- **Hosted Zone**: $0.50/month per zone
- **DNS Queries**: $0.40 per million queries
- **ACME DNS Updates**: ~$0.001 per certificate

---

## 3. **DigitalOcean**

### Why DigitalOcean?
- ✅ Simple and straightforward
- ✅ Free DNS service
- ✅ Good for DO droplet users
- ✅ Clean API design

### Prerequisites
- DigitalOcean account
- Domain added to DO DNS
- Nameservers pointing to DigitalOcean

### Step-by-Step Setup

#### **1. Create Personal Access Token**

1. **Log in to DigitalOcean**
   - Visit: https://cloud.digitalocean.com/

2. **Navigate to API**
   - Left sidebar → **API**
   - Or visit: https://cloud.digitalocean.com/account/api/

3. **Generate New Token**
   - Click **Generate New Token**
   - Name: `Coolify Wildcard SSL`
   - Scopes: Select **Read** and **Write**

4. **Copy Token**
   - Copy immediately (shown only once)
   - Store securely

#### **2. Add Domain to DigitalOcean DNS**

1. Go to **Networking** → **Domains**
2. Add your domain
3. Update nameservers at registrar:
   ```
   ns1.digitalocean.com
   ns2.digitalocean.com
   ns3.digitalocean.com
   ```

#### **3. Configure in Coolify**

```
DNS Provider: DigitalOcean
API Token: [paste your token]
```

### Testing

```bash
# Test with curl
curl -X GET "https://api.digitalocean.com/v2/account" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"
```

### Rate Limits
- **Default**: 5,000 requests/hour
- Generally sufficient for SSL provisioning

### Cost
- **DNS Service**: FREE ✅
- No per-query charges

---

## 🔄 **Comparison Table**

| Feature | Cloudflare | Route53 | DigitalOcean |
|---------|-----------|---------|--------------|
| **Cost** | Free tier | $0.50/month | FREE |
| **DNS Propagation** | 2-5 min | 5-10 min | 3-7 min |
| **API Complexity** | Easy | Complex | Easy |
| **Authentication** | Token or Key | IAM Keys | Token |
| **Rate Limits** | 1,200/5min | High | 5,000/hour |
| **Global CDN** | ✅ Yes | ❌ No | ❌ No |
| **DNSSEC** | ✅ Yes | ✅ Yes | ✅ Yes |
| **Multi-level wildcards** | Paid only | ✅ Yes | ✅ Yes |
| **Best For** | Small-Medium | Enterprise | Simple setups |

---

## 🧪 **Testing Your Setup**

### 1. Test Credentials

```bash
# In Coolify UI
1. Configure DNS provider
2. Click "Test DNS Provider Connection"
3. Verify success message
```

### 2. Test DNS Record Creation

```bash
# Manual test with DNS provider
# Create test TXT record: test._acme-challenge.example.com
# Value: test-value-123

# Verify DNS propagation
dig TXT test._acme-challenge.example.com
# or
nslookup -type=TXT test._acme-challenge.example.com
```

### 3. Test Wildcard Certificate

1. Enable wildcard SSL in Coolify
2. Configure DNS provider
3. Deploy test application: `test.course-app.edesy.in`
4. Check certificate:
   ```bash
   openssl s_client -connect test.course-app.edesy.in:443 -servername test.course-app.edesy.in | grep "CN="
   ```

---

## 🐛 **Troubleshooting**

### Cloudflare

**Issue**: `Invalid API Token`
- **Solution**: Ensure token has `Zone:DNS:Edit` permission
- Verify token is for correct zone

**Issue**: `Zone not found`
- **Solution**: Check domain is added to Cloudflare
- Verify nameservers are updated

**Issue**: `Rate limit exceeded`
- **Solution**: Wait 5 minutes
- Use Let's Encrypt staging for testing

### Route53

**Issue**: `Access Denied`
- **Solution**: Check IAM policy includes required permissions
- Verify access keys are correct
- Ensure user has Route53 access

**Issue**: `Hosted Zone not found`
- **Solution**: Provide hosted zone ID explicitly
- Check domain exists in Route53
- Verify region is correct

**Issue**: `Signature mismatch`
- **Solution**: Verify secret access key is correct
- Check for special characters in credentials
- Ensure no extra spaces when pasting

### DigitalOcean

**Issue**: `Unauthorized`
- **Solution**: Verify token has Read + Write scopes
- Check token hasn't expired
- Regenerate token if needed

**Issue**: `Domain not found`
- **Solution**: Add domain to DigitalOcean DNS first
- Update nameservers at registrar
- Wait for NS propagation (24-48 hours)

### General DNS Issues

**Issue**: `DNS propagation timeout`
- **Solution**: Wait 5-10 minutes for DNS propagation
- Check DNS records at authoritative nameservers
- Use online DNS checker: https://dnschecker.org/

**Issue**: `Certificate not issued`
- **Solution**: Check Traefik logs: `docker logs coolify-proxy`
- Verify DNS-01 challenge completed
- Test with staging ACME first

---

## 🔒 **Security Best Practices**

### 1. Credential Management
- ✅ Use API tokens instead of global keys (when possible)
- ✅ Rotate credentials every 90 days
- ✅ Use separate tokens for staging and production
- ❌ Never commit credentials to git
- ❌ Never share tokens via unsecured channels

### 2. Least Privilege
- ✅ Grant only DNS edit permissions
- ✅ Restrict to specific zones/domains
- ✅ Use time-limited tokens when available
- ❌ Avoid using root/admin credentials

### 3. Monitoring
- ✅ Enable API access logging
- ✅ Set up alerts for unusual API activity
- ✅ Monitor certificate expiration
- ✅ Review token usage regularly

---

## 📚 **Additional Resources**

### Cloudflare
- [API Documentation](https://developers.cloudflare.com/api/)
- [Create API Token](https://developers.cloudflare.com/fundamentals/api/get-started/create-token/)
- [DNS API Reference](https://developers.cloudflare.com/api/operations/dns-records-for-a-zone-list-dns-records)

### AWS Route53
- [API Reference](https://docs.aws.amazon.com/Route53/latest/APIReference/Welcome.html)
- [IAM Policies](https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/access-control-overview.html)
- [Best Practices](https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/best-practices.html)

### DigitalOcean
- [API Documentation](https://docs.digitalocean.com/reference/api/)
- [DNS Endpoints](https://docs.digitalocean.com/reference/api/api-reference/#tag/Domains)
- [Personal Access Tokens](https://docs.digitalocean.com/reference/api/create-personal-access-token/)

---

## ✅ **Quick Start Checklist**

- [ ] Choose DNS provider based on comparison table
- [ ] Create API credentials with correct permissions
- [ ] Test credentials using provider-specific test method
- [ ] Add domain to DNS provider (if needed)
- [ ] Update nameservers at registrar (if needed)
- [ ] Configure in Coolify UI
- [ ] Test connection
- [ ] Enable wildcard SSL
- [ ] Deploy test application
- [ ] Verify SSL certificate issued
- [ ] Test multiple subdomains
- [ ] Document credentials securely
- [ ] Set up monitoring/alerts

---

## 🆘 **Getting Help**

If you're still having issues:

1. Check provider status pages:
   - [Cloudflare Status](https://www.cloudflarestatus.com/)
   - [AWS Status](https://status.aws.amazon.com/)
   - [DigitalOcean Status](https://status.digitalocean.com/)

2. Review Coolify logs:
   ```bash
   docker logs coolify-proxy
   docker logs coolify
   ```

3. Check DNS propagation:
   - https://dnschecker.org/
   - https://www.whatsmydns.net/

4. Create GitHub issue:
   - https://github.com/coollabsio/coolify/issues

---

**Happy SSL provisioning!** 🎉🔒
