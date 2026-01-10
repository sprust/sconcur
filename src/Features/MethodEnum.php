<?php

namespace SConcur\Features;

enum MethodEnum: int
{
    case Sleep             = 1;
    case MongodbCollection = 2;
    case MongodbStateful   = 3;
}
