# codebit.nl marketing pages

Source for the public product pages at **https://codebit.nl/addon-expert**
(landing, docs, support, changelog). Mirrors the CF Image page style.

## Deploy

Server: `CaliMonk@donvigames.tilaa.cloud`
Webroot: `/bigdisk/docs/PHP/codebit/codebit.nl/addon-expert/`

```bash
# from repo root: refresh the asset copies, then scp the tree up
cp docs/screenshots/*.png docs/site/addon-expert/screenshots/
cp icon.svg docs/site/addon-expert/icon.svg
scp -i ~/.ssh/id_ed25519_mcp -r docs/site/addon-expert \
  CaliMonk@donvigames.tilaa.cloud:/bigdisk/docs/PHP/codebit/codebit.nl/
```

Screenshots and `icon.svg` in `addon-expert/` are copies of
`docs/screenshots/` and the repo-root `icon.svg`; they're git-ignored here
(regenerate with the `cp` lines above before deploying).
