# Automation Scripts Guide for Burst Statistics Plugin

This README helps you with the automation scripts in the `/automation/` folder of the Burst Statistics Plugin repo.

## Table of Contents

- [Introduction](#introduction)
- [Prerequisites](#prerequisites)
- [SSH Key Setup](#ssh-key-setup)
- [Setting Permissions](#setting-permissions)
- [How to Use create_rc.sh](#how-to-use-create_rcsh)
- [Troubleshooting](#troubleshooting)

## Introduction

These scripts make it easier to manage releases and handle translation files. Follow these steps to get started.

## Prerequisites

Make sure you have these installed:

1. `bash`
2. `wp-cli`
3. `rsync` and `zip`

## SSH Key Setup

1. **Open Terminal**
2. **Generate SSH Key**: Run `ssh-keygen -t rsa -b 4096 -C "your@email.com"`
3. **Save Key**: Hit enter three times.
4. **Copy SSH Key**: Run `cat ~/.ssh/id_rsa.pub`
5. **Add SSH Key**: Add this key to your server SFTP user.

## Setting Permissions

Go to `/automation/` in terminal and run:

```bash
chmod +x create_rc.sh
```

## How to Use `create_rc.sh`

This script helps you create a release for Burst Pro.

**How to Run**:
```bash
./create_rc.sh username [--upload]
```

## Troubleshooting

If you face issues:

1. Make sure prerequisites are set.
2. Check SSH keys.
3. Verify you can execute the scripts.
