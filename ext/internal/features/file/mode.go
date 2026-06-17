package file_feature

import "os"

// modeToFlags maps an fopen-style mode string to os.OpenFile flags. The mode is
// already validated/normalized on the PHP side (the binary/text suffix b/t is
// stripped there), but this is the single source of truth for the platform flag
// constants, so the PHP side never hardcodes them. An unknown mode is an error.
//
// PHP: SConcur\Features\File\FileMode.
func modeToFlags(mode string) (int, bool) {
	switch mode {
	case "r":
		return os.O_RDONLY, true
	case "r+":
		return os.O_RDWR, true
	case "w":
		return os.O_WRONLY | os.O_CREATE | os.O_TRUNC, true
	case "w+":
		return os.O_RDWR | os.O_CREATE | os.O_TRUNC, true
	case "a":
		return os.O_WRONLY | os.O_CREATE | os.O_APPEND, true
	case "a+":
		return os.O_RDWR | os.O_CREATE | os.O_APPEND, true
	case "x":
		return os.O_WRONLY | os.O_CREATE | os.O_EXCL, true
	case "x+":
		return os.O_RDWR | os.O_CREATE | os.O_EXCL, true
	case "c":
		return os.O_WRONLY | os.O_CREATE, true
	case "c+":
		return os.O_RDWR | os.O_CREATE, true
	default:
		return 0, false
	}
}
