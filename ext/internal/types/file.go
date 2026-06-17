package types

// FileCommand selects a sub-operation of the File feature, carried in the payload
// envelope's `cm` (like SqlCommand and HttpClientCommand).
// PHP: SConcur\Features\File\FileCommandEnum.
type FileCommand int

const (
	FileOpen     FileCommand = 1
	FileRead     FileCommand = 2
	FileWrite    FileCommand = 3
	FileSeek     FileCommand = 4
	FileTruncate FileCommand = 5
	FileSync     FileCommand = 6
	FileStat     FileCommand = 7
	FileClose    FileCommand = 8
)
