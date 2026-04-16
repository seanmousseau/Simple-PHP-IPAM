# Fira Code Subset

Source: [Fira Code v6.2](https://github.com/tonsky/FiraCode/releases/tag/6.2) (OFL-1.1)

Subset to IP/MAC display characters only (digits, hex a-f/A-F, `.`, `:`, `,`, `/`, `-`, space):

```bash
pyftsubset FiraCode-Regular.woff2 \
  --unicodes="U+0030-0039,U+0061-0066,U+0041-0046,U+002E,U+003A,U+002C,U+002F,U+002D,U+0020" \
  --flavor=woff2 \
  --output-file=fira-code-subset.woff2
```

Requires `fonttools` and `brotli` Python packages (`pipx install fonttools && pipx inject fonttools brotli`).
