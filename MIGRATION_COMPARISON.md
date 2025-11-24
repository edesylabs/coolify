# Migration Options Comparison

Quick comparison of the two migration approaches to help you choose the right one.

## Option 1: In-Place Upgrade (Same Server)

**What it does:** Upgrades your existing Coolify installation to use custom images

### Pros ✅
- **Fast**: 5-10 minutes total
- **Simple**: Just update configs and restart
- **No data transfer**: Everything stays on same server
- **Same IP address**: No DNS changes needed
- **Low complexity**: Minimal steps

### Cons ❌
- **Limited testing**: Can't test before committing
- **Brief UI downtime**: ~2-3 minutes during restart (apps stay up)
- **Single rollback point**: Only one backup before upgrade

### Best For 👍
- Development/staging environments
- Single Coolify server setups
- When you need quick upgrade
- When you trust your custom images
- When downtime window is available

### Commands
```bash
# Download and run unified script
wget https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh
chmod +x coolify-custom.sh
sudo ./coolify-custom.sh
# Select "Upgrade to Custom Coolify (In-Place)"
```

### Timeline
| Step | Duration |
|------|----------|
| Backup | 2 min |
| Update config | 1 min |
| Pull images | 2-3 min |
| Restart | 2-3 min |
| **Total** | **~5-10 min** |

---

## Option 2: New Server Migration

**What it does:** Install custom Coolify on new server and migrate all data

### Pros ✅
- **Thorough testing**: Test before cutover
- **Zero risk**: Old server stays intact
- **Parallel running**: Run both simultaneously
- **Easy rollback**: Just switch back DNS
- **Production safe**: No impact on current system

### Cons ❌
- **Slower**: 45-60 minutes total
- **More complex**: Multiple steps
- **Requires new server**: Additional infrastructure cost
- **Data transfer**: Need to move backup between servers
- **DNS updates**: May need to update DNS/webhooks

### Best For 👍
- Production environments
- When you need thorough testing
- When you have spare server capacity
- When zero risk tolerance is required
- When you want to run both in parallel

### Commands

**On OLD server:**
```bash
wget https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/backup-for-migration.sh
chmod +x backup-for-migration.sh
./backup-for-migration.sh
scp /root/coolify-migration-*.tar.gz root@NEW_SERVER:/root/
```

**On NEW server:**
```bash
# Install custom Coolify
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh -o coolify.sh
chmod +x coolify.sh
sudo ./coolify.sh  # Select "Install Custom Coolify"

# Restore data
wget https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/restore-from-migration.sh
chmod +x restore-from-migration.sh
./restore-from-migration.sh
```

### Timeline
| Step | Duration |
|------|----------|
| Backup old | 5-10 min |
| Transfer | 5-10 min |
| Install new | 10-15 min |
| Restore data | 10-15 min |
| Verification | 15-20 min |
| **Total** | **~45-60 min** |

---

## Side-by-Side Comparison

| Feature | In-Place Upgrade | New Server Migration |
|---------|-----------------|---------------------|
| **Time Required** | 5-10 min | 45-60 min |
| **Complexity** | Low | Medium |
| **Risk Level** | Low | Very Low |
| **Testing Ability** | Limited | Extensive |
| **Rollback Speed** | < 5 min | Immediate (DNS switch) |
| **Server Cost** | $0 | +1 server cost |
| **Coolify UI Downtime** | 2-3 min | 0 min |
| **App Downtime** | 0 min | 0 min |
| **Data Transfer** | None | Required |
| **IP Address** | Same | New |
| **DNS Changes** | None | Maybe |
| **Webhook Updates** | None | Required |
| **Parallel Testing** | No | Yes |
| **Production Ready** | Good | Excellent |

---

## Decision Matrix

### Choose In-Place Upgrade If:
- ✅ You have a single Coolify server
- ✅ This is dev/staging environment
- ✅ You need it done quickly
- ✅ You're comfortable with 2-3 min UI downtime
- ✅ You've tested custom images elsewhere
- ✅ Keeping same IP is important

### Choose New Server Migration If:
- ✅ This is production environment
- ✅ You need zero-risk migration
- ✅ You want to test thoroughly first
- ✅ You can afford a new server temporarily
- ✅ You want to run both in parallel
- ✅ Your team needs confidence before cutover

---

## Migration Path Recommendations

### For Development/Staging:
```
In-Place Upgrade
├─ Quick and simple
├─ Test custom changes fast
└─ Iterate quickly
```

### For Small Production:
```
In-Place Upgrade (with backup)
├─ Schedule during maintenance window
├─ Create full backup first
├─ Test rollback procedure
└─ Monitor closely after upgrade
```

### For Large Production:
```
New Server Migration
├─ Install on new server
├─ Test thoroughly
├─ Run parallel for 24-48h
├─ Gradual traffic cutover
└─ Keep old server as backup for 1-2 weeks
```

---

## What Stays the Same

Both approaches preserve:
- ✅ All servers and connections
- ✅ All applications and deployments
- ✅ All databases (metadata)
- ✅ All environment variables
- ✅ All user accounts and teams
- ✅ All SSH keys and certificates
- ✅ All configurations
- ✅ **Zero downtime for your applications**

Both result in:
- ✅ Using your custom Docker images
- ✅ Updates from your GitHub repository
- ✅ New servers use your custom installation
- ✅ Full control over Coolify codebase

---

## Application Downtime: ZERO in Both Cases

**Important**: Your deployed applications have **ZERO downtime** in both scenarios because:

1. **Applications run on separate servers** (Server B, C, D)
2. **Only Coolify control plane moves** (Server A)
3. **Applications are independent** of Coolify runtime
4. **Docker containers keep running** on application servers

```
Applications (Server B, C, D)
├─ Keep running ✅
├─ Keep serving traffic ✅
├─ Not affected by Coolify migration ✅
└─ Databases keep running ✅

Coolify Control Plane (Server A or E)
├─ Being migrated
└─ UI may be briefly unavailable
```

---

## Cost Analysis

### In-Place Upgrade
- **Server Cost**: $0 (same server)
- **Time Cost**: ~10 minutes
- **Risk Cost**: Low (can rollback)
- **Total**: Minimal

### New Server Migration
- **Server Cost**: +$5-10/month temporarily
- **Time Cost**: ~1 hour
- **Risk Cost**: Very low (old server intact)
- **Long-term**: Can decommission old server after

**Tip**: For production, the extra $5-10 for peace of mind during migration is worth it.

---

## Common Questions

### Q: Can I test before committing?
- **In-Place**: Limited. Create backup, upgrade, test, rollback if needed.
- **New Server**: Yes! Fully test on new server before cutover.

### Q: What if something goes wrong?
- **In-Place**: Rollback script available, ~5 minutes to restore.
- **New Server**: Just keep using old server, new server is isolated.

### Q: Will my applications go down?
- **Both**: No! Applications continue running on their servers.

### Q: Can I switch back if I don't like it?
- **In-Place**: Yes, rollback script provided.
- **New Server**: Yes, just switch DNS back to old server.

### Q: Which is safer for production?
- **Answer**: New Server Migration is safer, but In-Place is fine with proper backup.

### Q: Which is faster?
- **Answer**: In-Place (5-10 min) vs New Server (45-60 min).

### Q: Do I need to update DNS?
- **In-Place**: No, same IP address.
- **New Server**: Only if using custom domain for Coolify.

### Q: Will my team need to re-login?
- **Both**: No, sessions are preserved in database.

---

## Recommended Approach by Scenario

| Scenario | Recommended | Reason |
|----------|------------|--------|
| First time trying custom fork | In-Place | Quick iteration, easy to revert |
| Dev/Staging environment | In-Place | Speed and simplicity |
| Small production (< 10 apps) | In-Place | With backup, acceptable risk |
| Large production (> 10 apps) | New Server | Zero-risk approach |
| Multiple teams using it | New Server | Extensive testing needed |
| Critical business apps | New Server | Safety first |
| Testing custom features | In-Place | Quick feedback loop |
| Compliance requirements | New Server | Audit trail, testing required |

---

## Final Recommendation

### Start with In-Place Upgrade if:
You're confident in your custom images and want quick results.

```bash
# One command upgrade with unified script
wget https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh
chmod +x coolify-custom.sh
sudo ./coolify-custom.sh
# Select "Upgrade to Custom Coolify (In-Place)"
```

### Go with New Server Migration if:
You need thorough testing and zero-risk deployment.

```bash
# Full migration with testing
# See MIGRATION_GUIDE.md for complete process
```

---

## Need Help Deciding?

**Quick Decision Tree:**

```
Do you have a spare server available?
├─ No  → In-Place Upgrade
└─ Yes → Is this production?
    ├─ No  → In-Place Upgrade (faster)
    └─ Yes → Do you need thorough testing?
        ├─ No  → In-Place Upgrade (with backup)
        └─ Yes → New Server Migration
```

---

**Both approaches work great. Choose based on your needs!** 🚀

- **Quick upgrade**: [IN_PLACE_UPGRADE_GUIDE.md](./IN_PLACE_UPGRADE_GUIDE.md)
- **Safe migration**: [MIGRATION_GUIDE.md](./MIGRATION_GUIDE.md)
- **Fresh install**: [QUICK_START.md](./QUICK_START.md)
