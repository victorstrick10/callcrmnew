# Superpowers (project)

This project vendors the official **Superpowers** skills:
https://claude.com/plugins/superpowers
https://github.com/obra/superpowers

Skills live in `.cursor/skills/` so Cloud Agents and Cursor both load them
without a marketplace install.

Cursor desktop can still enable the marketplace plugin (already on in
`.cursor/settings.json`):

```text
/add-plugin superpowers
```

Verify with: "Do you have superpowers?"

Claude Code: `/plugin install superpowers@claude-plugins-official`
