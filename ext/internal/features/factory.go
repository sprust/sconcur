package features

import (
	"errors"
	"fmt"
	"sconcur/internal/contracts"
	"sconcur/internal/features/mongodb_feature"
	"sconcur/internal/features/sleep_feature"
	"sconcur/internal/types"
)

func DetectMessageHandler(method types.Method) (contracts.MessageHandler, error) {
	if method == 1 {
		return sleep_feature.New(), nil
	}

	if method == 2 {
		return mongodb_feature.NewCollection(), nil
	}

	return nil, errors.New("unknown method: " + fmt.Sprint(method))
}
